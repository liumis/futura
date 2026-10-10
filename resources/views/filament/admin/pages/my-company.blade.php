<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <form wire:submit="save" class="space-y-4">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" size="lg">
                        Save company details
                    </x-filament::button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Bank accounts</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Company IBANs used for payments and statement imports.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    {{ $this->syncAccountsFromStatementsAction }}
                    {{ $this->importBankDataAction }}
                    {{ $this->addBankAccountAction }}
                </div>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($this->bankAccountGroups() as $bank => $accounts)
                    <div x-data="{ open: true }" class="px-4 py-3">
                        <button
                            type="button"
                            class="mb-2 flex w-full items-center gap-2 text-left text-sm font-semibold text-gray-800 dark:text-gray-100"
                            @click="open = ! open"
                        >
                            <x-heroicon-o-chevron-down
                                class="h-4 w-4 text-gray-400 transition-transform"
                                x-bind:class="open ? 'rotate-180' : ''"
                            />
                            {{ $bank }}
                        </button>

                        <div x-show="open" class="space-y-1">
                            @foreach ($accounts as $account)
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                    <div class="min-w-0">
                                        <a
                                            href="{{ \App\Filament\Admin\Pages\BankStatements::getUrl() }}"
                                            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            title="Open bank statements"
                                        >
                                            {{ $account->iban }}
                                            @if (filled($account->name))
                                                <span class="font-normal text-gray-700 dark:text-gray-200">{{ $account->name }}</span>
                                            @endif
                                        </a>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $account->type?->label() }}</span>
                                            @if ($account->is_primary)
                                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">Primary</span>
                                            @endif
                                            @if (filled($account->bic))
                                                <span>BIC {{ $account->bic }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs">
                                        <button
                                            type="button"
                                            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            wire:click="mountAction('editBankAccount', { accountId: {{ $account->getKey() }} })"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="font-medium text-rose-600 hover:underline dark:text-rose-400"
                                            wire:click="mountAction('deleteBankAccount', { accountId: {{ $account->getKey() }} })"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                        No bank accounts yet. Add one or import a bank statement.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Credit card accounts</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Card settlement accounts and limits.</p>
                </div>
                <button
                    type="button"
                    wire:click="toggleCreditCards"
                    class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    {{ $this->showCreditCards ? 'Hide cards' : 'Show cards' }}
                </button>
            </div>

            @if ($this->showCreditCards)
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2">Account</th>
                                <th class="px-4 py-2 text-right">Credit limit</th>
                                <th class="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->creditCardAccounts() as $account)
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-primary-600 dark:text-primary-400">
                                            {{ $account->iban }}
                                            @if (filled($account->name))
                                                <span class="font-normal text-gray-700 dark:text-gray-200">{{ $account->name }}</span>
                                            @endif
                                        </div>
                                        @if (filled($account->bic))
                                            <div class="text-xs text-gray-500 dark:text-gray-400">BIC {{ $account->bic }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                        {{ $this->formatMoney($account->credit_limit) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex items-center gap-3 text-xs">
                                            <button
                                                type="button"
                                                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                                wire:click="mountAction('editBankAccount', { accountId: {{ $account->getKey() }} })"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                class="font-medium text-rose-600 hover:underline dark:text-rose-400"
                                                wire:click="mountAction('deleteBankAccount', { accountId: {{ $account->getKey() }} })"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-6 text-gray-500 dark:text-gray-400">
                                        No credit card accounts. Use Add bank account and set type to Credit card.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
