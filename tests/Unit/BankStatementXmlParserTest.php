<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementDuplicateException;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BankStatements\BankStatementXmlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementXmlParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_income_and_outcome_and_groups_by_month(): void
    {
        $parsed = app(BankStatementXmlParser::class)->parse($this->camtXml());

        $this->assertSame('Swedbank', $parsed['bank_name']);
        $this->assertSame('LT123456789012345678', $parsed['account_iban']);
        $this->assertCount(2, $parsed['transactions']);
        $this->assertSame(BankStatementDirection::Credit, $parsed['transactions'][0]['direction']);
        $this->assertSame(150.5, $parsed['transactions'][0]['amount']);
        $this->assertSame('2026-08-03', $parsed['transactions'][0]['booking_date']);
        $this->assertSame(BankStatementDirection::Debit, $parsed['transactions'][1]['direction']);
        $this->assertSame('2026-07-31', $parsed['transactions'][1]['booking_date']);
    }

    public function test_second_upload_merges_into_existing_month_and_skips_duplicates(): void
    {
        $importer = app(BankStatementImporter::class);

        $first = $importer->importXml($this->camtXml(), 'swedbank.xml', comment: 'August Swedbank file');
        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame('August Swedbank file', $first['import']->comment);
        $this->assertSame(
            'August Swedbank file',
            BankStatementTransaction::query()->where('month_key', '2026-08')->value('comment'),
        );

        $line = BankStatementTransaction::query()->where('month_key', '2026-08')->first();
        $line->update(['comment' => 'Customer paid invoice 1001']);
        $this->assertSame('Customer paid invoice 1001', $line->fresh()->displayComment());

        try {
            $importer->importXml($this->camtXml(), 'swedbank-again.xml');
            $this->fail('Expected a duplicate statement to be rejected.');
        } catch (BankStatementDuplicateException $exception) {
            $this->assertStringContainsString('already imported', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, BankStatementImport::query()->count());
        $this->assertSame(2, BankStatementTransaction::query()->count());

        $secondBank = $importer->importXml($this->secondBankXml(), 'seb.xml');
        $this->assertSame(1, $secondBank['imported']);

        $august = BankStatementTransaction::query()
            ->where('month_key', '2026-08')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $august);
        $this->assertSame('Customer UAB', $august[0]->counterpart_name);
        $this->assertSame('SEB client', $august[1]->counterpart_name);
    }

    public function test_overlapping_file_imports_only_new_lines(): void
    {
        $importer = app(BankStatementImporter::class);
        $first = $importer->importXml($this->camtXml(), 'swedbank.xml');
        $this->assertSame(2, $first['imported']);

        $partial = $importer->importXml($this->overlappingXml(), 'swedbank-overlap.xml');
        $this->assertSame(1, $partial['imported']);
        $this->assertSame(1, $partial['skipped']);
        $this->assertSame(2, BankStatementImport::query()->count());
        $this->assertSame(3, BankStatementTransaction::query()->count());
        $this->assertTrue(
            BankStatementTransaction::query()->where('account_servicer_ref', 'ASR-3')->exists(),
        );
    }

    public function test_restated_file_with_the_same_lines_is_rejected(): void
    {
        $importer = app(BankStatementImporter::class);
        $importer->importXml($this->camtXml(), 'swedbank.xml');

        try {
            $importer->importXml($this->restatedCamtXml(), 'swedbank-restated.xml');
            $this->fail('Expected a duplicate statement to be rejected.');
        } catch (BankStatementDuplicateException $exception) {
            $this->assertStringContainsString('already in the system', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, BankStatementImport::query()->count());
        $this->assertSame(2, BankStatementTransaction::query()->count());
    }

    private function camtXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <GrpHdr><MsgId>MSG-1</MsgId></GrpHdr>
    <Stmt>
      <Id>STMT-1</Id>
      <Acct>
        <Id><IBAN>LT123456789012345678</IBAN></Id>
        <Ccy>EUR</Ccy>
        <Svcr><FinInstnId><BIC>HABALT22</BIC><Nm>Swedbank</Nm></FinInstnId></Svcr>
      </Acct>
      <Ntry>
        <Amt Ccy="EUR">150.50</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-08-03</Dt></BookgDt>
        <AcctSvcrRef>ASR-1</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Dbtr><Nm>Customer UAB</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Invoice 1001</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">40.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <BookgDt><Dt>2026-07-31</Dt></BookgDt>
        <AcctSvcrRef>ASR-2</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Cdtr><Nm>Supplier UAB</Nm></Cdtr></RltdPties>
          <RmtInf><Ustrd>Rent</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
    }

    private function restatedCamtXml(): string
    {
        return str_replace(
            ['<MsgId>MSG-1</MsgId>', '<Id>STMT-1</Id>'],
            ['<MsgId>MSG-1-RESTATED</MsgId>', '<Id>STMT-1-RESTATED</Id>'],
            $this->camtXml(),
        );
    }

    private function overlappingXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <GrpHdr><MsgId>MSG-1B</MsgId></GrpHdr>
    <Stmt>
      <Id>STMT-1B</Id>
      <Acct>
        <Id><IBAN>LT123456789012345678</IBAN></Id>
        <Ccy>EUR</Ccy>
        <Svcr><FinInstnId><BIC>HABALT22</BIC><Nm>Swedbank</Nm></FinInstnId></Svcr>
      </Acct>
      <Ntry>
        <Amt Ccy="EUR">150.50</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-08-03</Dt></BookgDt>
        <AcctSvcrRef>ASR-1</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Dbtr><Nm>Customer UAB</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Invoice 1001</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">25.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-08-12</Dt></BookgDt>
        <AcctSvcrRef>ASR-3</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Dbtr><Nm>New customer</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Invoice 1002</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
    }

    private function secondBankXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <GrpHdr><MsgId>MSG-2</MsgId></GrpHdr>
    <Stmt>
      <Id>STMT-2</Id>
      <Acct>
        <Id><IBAN>LT998877665544332211</IBAN></Id>
        <Ccy>EUR</Ccy>
        <Svcr><FinInstnId><BIC>CBVILT2X</BIC><Nm>SEB</Nm></FinInstnId></Svcr>
      </Acct>
      <Ntry>
        <Amt Ccy="EUR">80.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-08-10</Dt></BookgDt>
        <AcctSvcrRef>SEB-1</AcctSvcrRef>
        <NtryDtls><TxDtls>
          <RltdPties><Dbtr><Nm>SEB client</Nm></Dbtr></RltdPties>
          <RmtInf><Ustrd>Payment</Ustrd></RmtInf>
        </TxDtls></NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;
    }
}
