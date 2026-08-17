<div
    x-show="quickViewModalOpen"
    x-cloak
    class="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4"
    @keydown.escape.window="closeQuickView()"
>
    <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900" @click.outside="closeQuickView()">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                <span x-show="quickViewMode === 'create'">New task</span>
                <span x-show="quickViewMode !== 'create'">
                    Task: <span x-text="quickViewItem?.display_title ?? quickViewItem?.title ?? ''"></span>
                </span>
            </h3>
            <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" @click="closeQuickView()">✕</button>
        </div>

        <div class="space-y-3 overflow-y-auto p-4">
            <div x-show="quickViewLoading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Loading...
            </div>

            <div x-show="!quickViewLoading" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Title</label>
                    <input type="text" x-model="quickViewForm.title" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Project <span class="text-red-600">*</span></label>
                    <select x-model="quickViewForm.project_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)" required>
                        <option value="">Select project</option>
                        <template x-for="project in projects.filter((entry) => !entry.archived || String(entry.id) === String(quickViewForm.project_id))" :key="project.id">
                            <option :value="String(project.id)" x-text="project.label"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Deadline</label>
                    <input type="datetime-local" x-model="quickViewForm.deadline" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Start date</label>
                    <input type="datetime-local" x-model="quickViewForm.start_date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Description</label>
                    <textarea x-model="quickViewForm.description" rows="5" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)"></textarea>
                </div>

                <div class="md:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" x-model="quickViewForm.has_finances" class="rounded border-gray-300 text-primary-600" :disabled="!(quickViewItem?.can_edit)">
                        <span>Finances</span>
                    </label>
                </div>

                <div class="md:col-span-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700" x-show="quickViewForm.has_finances">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Finances</div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Total income</label>
                            <input type="number" min="0" step="0.01" x-model="quickViewForm.total_income" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Income left</label>
                            <input type="number" min="0" step="0.01" x-model="quickViewForm.income_left" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Total expences</label>
                            <input type="number" min="0" step="0.01" x-model="quickViewForm.total_payment" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Expences left</label>
                            <input type="number" min="0" step="0.01" x-model="quickViewForm.payment_left" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Watchers</label>
                    <select
                        multiple
                        x-model="quickViewForm.watcher_ids"
                        class="min-h-[7.5rem] w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        :disabled="!(quickViewItem?.can_edit)"
                    >
                        <template x-for="user in users" :key="user.id">
                            <option :value="String(user.id)" x-text="user.label"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Hold Ctrl/Cmd to select multiple.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Attachments</label>
                    <ul class="mb-2 space-y-1" x-show="(quickViewForm.attachments ?? []).length > 0">
                        <template x-for="(file, index) in quickViewForm.attachments" :key="'att-' + index + '-' + (file?.item_id || file?.name || file)">
                            <li class="flex items-center justify-between gap-2 rounded-md border border-gray-200 px-2 py-1.5 text-xs dark:border-gray-700">
                                <a :href="attachmentUrl(file)" target="_blank" class="truncate text-primary-600 underline" x-text="attachmentLabel(file)"></a>
                                <button
                                    type="button"
                                    class="shrink-0 text-red-600 hover:underline"
                                    x-show="quickViewItem?.can_edit"
                                    @click="removeAttachment(index)"
                                >Remove</button>
                            </li>
                        </template>
                    </ul>
                    <ul class="mb-2 space-y-1" x-show="pendingUploadNames.length > 0">
                        <template x-for="(name, index) in pendingUploadNames" :key="'pending-' + index + '-' + name">
                            <li class="rounded-md border border-dashed border-primary-300 px-2 py-1.5 text-xs text-primary-700 dark:border-primary-700 dark:text-primary-300">
                                Pending upload: <span x-text="name"></span>
                            </li>
                        </template>
                    </ul>
                    <input
                        type="file"
                        multiple
                        class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-gray-300 dark:file:bg-gray-800"
                        :disabled="!(quickViewItem?.can_edit) || uploadingAttachments"
                        @change="onAttachmentsSelected($event)"
                    >
                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400" x-show="uploadingAttachments">Uploading...</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">User</label>
                    <select x-model="quickViewForm.user_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        <option value="">Select user</option>
                        <template x-for="user in users" :key="'owner-' + user.id">
                            <option :value="String(user.id)" x-text="user.label"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Status</label>
                    <select x-model="quickViewForm.status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        <option value="new">New</option>
                        <option value="inprogress">In progress</option>
                        <option value="confirm">Confirm</option>
                        <option value="returned">Returned</option>
                        <option value="done">Done</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Priority</label>
                    <select x-model="quickViewForm.priority" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        <option value="high">High</option>
                        <option value="regular">Regular</option>
                        <option value="low">Low</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="quick-view-archived" x-model="quickViewForm.archived" class="rounded border-gray-300 text-primary-600" :disabled="!(quickViewItem?.can_edit)">
                    <label for="quick-view-archived" class="text-xs font-medium text-gray-600 dark:text-gray-300">Archived</label>
                </div>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400" x-show="!quickViewLoading && !(quickViewItem?.can_edit)">
                Only the task author can edit this item.
                <a x-show="quickViewItem?.edit_url" :href="quickViewItem?.edit_url" class="text-primary-600 underline">Open full edit page</a>
            </p>
        </div>

        <div class="flex justify-end gap-2 border-t border-gray-200 p-4 dark:border-gray-700">
            <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" @click="closeQuickView()">Close</button>
            <button type="button" class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60" :disabled="savingQuickView || quickViewLoading || uploadingAttachments || !(quickViewItem?.can_edit)" @click="saveQuickView()">
                <span x-show="!savingQuickView && quickViewMode === 'create'">Create task</span>
                <span x-show="!savingQuickView && quickViewMode !== 'create'">Save changes</span>
                <span x-show="savingQuickView">Saving...</span>
            </button>
        </div>
    </div>
</div>
