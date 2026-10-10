<div
    x-show="quickViewModalOpen"
    x-cloak
    class="contact-qv fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4"
    @keydown.escape.window="closeQuickView()"
    @click.self="closeQuickView()"
>
    <div class="contact-qv__box" @click.stop>
        <div class="contact-qv__header">
            <h3 class="contact-qv__title">
                Contact: <span x-text="quickViewItem?.title ?? ''"></span>
            </h3>
            <button type="button" class="contact-qv__close" @click="closeQuickView()">✕</button>
        </div>

        <div class="contact-qv__tabs" x-show="!quickViewLoading">
            <button
                type="button"
                class="contact-qv__tab"
                :class="{ 'is-active': quickViewTab === 'summary' }"
                @click="quickViewTab = 'summary'"
            >
                Summary
            </button>
            <button
                type="button"
                class="contact-qv__tab"
                :class="{ 'is-active': quickViewTab === 'contacts' }"
                @click="quickViewTab = 'contacts'"
            >
                Contacts
                <span
                    class="contact-qv__tab-count"
                    x-show="(quickViewForm.persons ?? []).length > 0"
                    x-text="(quickViewForm.persons ?? []).length"
                ></span>
            </button>
        </div>

        <div class="contact-qv__body space-y-4">
            <div x-show="quickViewLoading" class="contact-qv__loading">
                Loading...
            </div>

            <div x-show="!quickViewLoading && quickViewTab === 'summary'" class="space-y-4">
                <div>
                    <label class="contact-qv__label">Type</label>
                    <select x-model="quickViewForm.type" class="contact-qv__input">
                        <option value="">—</option>
                        <option value="Distributor">Distributor</option>
                        <option value="Manufacturer">Manufacturer</option>
                        <option value="Retailer">Retailer</option>
                        <option value="Contract">Contract</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="contact-qv__label">Phone</label>
                        <input type="text" x-model="quickViewForm.phone" class="contact-qv__input" placeholder="+370...">
                    </div>
                    <div>
                        <label class="contact-qv__label">Email</label>
                        <input type="email" x-model="quickViewForm.email" class="contact-qv__input" placeholder="name@company.com">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="contact-qv__label">Last contact</label>
                        <input type="date" x-model="quickViewForm.last_contact" class="contact-qv__input">
                    </div>
                    <div>
                        <label class="contact-qv__label">Next contact</label>
                        <input type="date" x-model="quickViewForm.next_contact" class="contact-qv__input">
                    </div>
                </div>

                <div>
                    <label class="contact-qv__label">Comment (for myself)</label>
                    <textarea x-model="quickViewForm.comment" rows="4" class="contact-qv__input" placeholder="Add a new comment"></textarea>
                </div>

                <div x-show="(quickViewForm.comments ?? []).length > 0" class="space-y-2">
                    <p class="contact-qv__label">Recent comments</p>
                    <div class="contact-qv__comments">
                        <template x-for="(entry, index) in (quickViewForm.comments ?? [])" :key="index">
                            <div class="contact-qv__comment">
                                <div class="contact-qv__comment-date" x-text="entry.commented_at || '—'"></div>
                                <div class="contact-qv__comment-text" x-text="entry.comment"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <p>
                    <a x-show="quickViewItem?.edit_url" :href="quickViewItem?.edit_url" class="contact-qv__link">Open full contact</a>
                </p>
            </div>

            <div x-show="!quickViewLoading && quickViewTab === 'contacts'" class="space-y-3">
                <template x-for="(person, index) in (quickViewForm.persons ?? [])" :key="'person-' + index">
                    <div class="contact-qv__person">
                        <div class="contact-qv__person-name" x-text="person.name || '—'"></div>
                        <div class="contact-qv__person-meta" x-show="person.phone" x-text="person.phone"></div>
                        <div class="contact-qv__person-meta" x-show="person.email" x-text="person.email"></div>
                        <div class="contact-qv__person-note" x-show="person.comment" x-text="person.comment"></div>
                    </div>
                </template>

                <div
                    class="contact-qv__empty"
                    x-show="(quickViewForm.persons ?? []).length === 0"
                >
                    No contacts for this company.
                </div>

                <p>
                    <a x-show="quickViewItem?.edit_url" :href="quickViewItem?.edit_url" class="contact-qv__link">Open full contact</a>
                </p>
            </div>
        </div>

        <div class="contact-qv__footer">
            <button type="button" class="contact-qv__btn contact-qv__btn--ghost" @click="closeQuickView()">Close</button>
            <button
                type="button"
                class="contact-qv__btn"
                x-show="quickViewTab === 'summary'"
                :disabled="savingQuickView || quickViewLoading"
                @click="saveQuickView()"
            >
                <span x-show="!savingQuickView">Save</span>
                <span x-show="savingQuickView">Saving...</span>
            </button>
        </div>
    </div>
</div>
