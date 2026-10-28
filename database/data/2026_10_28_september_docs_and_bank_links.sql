-- Reference dump of 2026-10-28 September document + bank link work.
-- Applied on deploy via migration:
--   2026_10_28_093000_import_september_2026_docs_and_bank_links.php
-- Do not run this SQL raw on production (IDs differ); the PHP migration matches by
-- invoice_number / fingerprint / trip dates and reuses SharePoint item IDs.

-- Invoices created/updated (SharePoint already holds the PDFs):
--   LT1526-31638   Bolt Operations     61.54  -> TX 2026-09-25 54.90 BOLT.EU
--   2622847779     Air Baltic          707.92 -> TX 2026-09-07 707.92
--   2022858        Litideal rent       625.65 -+
--   A10146252      Litideal admin       35.00 -+-> TX 2026-09-16 860.00 (overpayment_refunded; refund 199.35)
--   BALT2608105    Baltic Air Logistics 452.60 -> TX 2026-09-14 452.60

-- Business trip Deividas Arman 2026-09-16..2026-09-18:
--   expense report document (Komandiruotė) on SharePoint
--   expenses_amount = 671.27
--   bank links: 2026-09-17 28.00 IAA, 200.81 Plaza Hotel,
--               2026-09-18 295.00 H und H, 16.00 GfV Hannover

-- Contact ensured:
--   Baltic Air Logistics Terminal UAB (company_id 124430488, VAT LT244304811)
