/**
 * Favorite Multimedia — Audio Queue Manager (Chunk 3)
 * Handles queue ordering, deterministic shuffle, repeat modes, and item transitions.
 */
(function (window) {
    'use strict';

    class AudioQueue {
        constructor() {
            this.items = [];
            this.originalOrder = [];
            this.currentIndex = -1;
            this.repeatMode = 'off'; // 'off' | 'queue' | 'one'
            this.isShuffled = false;
            this.queueContext = 'manual';
            this.contextId = null;
        }

        initFromState(state) {
            if (!state || !Array.isArray(state.queue)) return;
            this.items = state.queue.slice();
            this.originalOrder = this.items.slice();
            this.currentIndex = typeof state.currentIndex === 'number' ? state.currentIndex : -1;
            this.repeatMode = state.repeatMode || 'off';
            this.isShuffled = !!state.isShuffled;
            this.queueContext = state.queueContext || 'manual';
            this.contextId = state.contextId || null;
        }

        setQueue(items, startIndex = 0, context = 'manual', contextId = null) {
            this.items = Array.isArray(items) ? items.slice() : [];
            this.originalOrder = this.items.slice();
            this.isShuffled = false;
            this.queueContext = context;
            this.contextId = contextId;

            if (this.items.length === 0) {
                this.currentIndex = -1;
            } else {
                this.currentIndex = Math.max(0, Math.min(this.items.length - 1, startIndex));
            }
        }

        getCurrentItem() {
            if (this.currentIndex >= 0 && this.currentIndex < this.items.length) {
                return this.items[this.currentIndex];
            }
            return null;
        }

        getCurrentIndex() {
            return this.currentIndex;
        }

        getItems() {
            return this.items.slice();
        }

        playNext(item) {
            if (!item) return;
            if (this.items.length === 0 || this.currentIndex < 0) {
                this.items = [item];
                this.originalOrder = [item];
                this.currentIndex = 0;
                return;
            }

            const insertPos = this.currentIndex + 1;
            this.items.splice(insertPos, 0, item);
            if (this.isShuffled) {
                this.originalOrder.push(item);
            }
        }

        addToQueue(item) {
            if (!item) return;
            if (this.items.length === 0) {
                this.items = [item];
                this.originalOrder = [item];
                this.currentIndex = 0;
                return;
            }

            this.items.push(item);
            if (this.isShuffled) {
                this.originalOrder.push(item);
            }
        }

        removeAt(index) {
            if (index < 0 || index >= this.items.length) return null;
            const removed = this.items.splice(index, 1)[0];

            if (this.items.length === 0) {
                this.currentIndex = -1;
            } else if (index < this.currentIndex) {
                this.currentIndex--;
            } else if (index === this.currentIndex && this.currentIndex >= this.items.length) {
                this.currentIndex = this.items.length - 1;
            }

            return removed;
        }

        reorder(orderedIds) {
            if (!Array.isArray(orderedIds) || this.items.length === 0) return;
            const currentItem = this.getCurrentItem();
            const currentId = currentItem ? currentItem.id : null;

            const map = new Map();
            this.items.forEach(it => map.set(it.id, it));

            const reordered = [];
            orderedIds.forEach(id => {
                if (map.has(id)) {
                    reordered.push(map.get(id));
                    map.delete(id);
                }
            });
            // Append any not explicitly in orderedIds
            map.forEach(rem => reordered.push(rem));

            this.items = reordered;

            // Restore currentIndex pointing to same item
            if (currentId !== null) {
                const foundIndex = this.items.findIndex(it => it.id === currentId);
                if (foundIndex !== -1) {
                    this.currentIndex = foundIndex;
                }
            }
        }

        clear() {
            this.items = [];
            this.originalOrder = [];
            this.currentIndex = -1;
            this.isShuffled = false;
        }

        next() {
            if (this.items.length === 0) return null;

            if (this.repeatMode === 'one') {
                return this.getCurrentItem();
            }

            const nextIndex = this.currentIndex + 1;
            if (nextIndex >= this.items.length) {
                if (this.repeatMode === 'queue') {
                    this.currentIndex = 0;
                    return this.getCurrentItem();
                }
                return null; // Reached end of queue
            }

            this.currentIndex = nextIndex;
            return this.getCurrentItem();
        }

        previous(currentTime = 0, threshold = 3.0) {
            if (this.items.length === 0) return { item: null, action: 'previous' };

            if (currentTime > threshold) {
                return { item: this.getCurrentItem(), action: 'restart' };
            }

            if (this.currentIndex > 0) {
                this.currentIndex--;
                return { item: this.getCurrentItem(), action: 'previous' };
            }

            if (this.repeatMode === 'queue') {
                this.currentIndex = this.items.length - 1;
                return { item: this.getCurrentItem(), action: 'previous' };
            }

            return { item: this.getCurrentItem(), action: 'restart' };
        }

        toggleShuffle() {
            if (this.items.length <= 1) {
                this.isShuffled = !this.isShuffled;
                return this.isShuffled;
            }

            if (this.isShuffled) {
                // Restore original canonical order
                const currentItem = this.getCurrentItem();
                this.items = this.originalOrder.slice();
                this.isShuffled = false;
                if (currentItem) {
                    const found = this.items.findIndex(it => it.id === currentItem.id);
                    if (found !== -1) this.currentIndex = found;
                }
            } else {
                // Shuffle keeping current item at index 0
                this.originalOrder = this.items.slice();
                const currentItem = this.getCurrentItem();
                const others = this.items.filter((_, idx) => idx !== this.currentIndex);

                // Fisher-Yates shuffle
                for (let i = others.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [others[i], others[j]] = [others[j], others[i]];
                }

                this.items = [currentItem, ...others];
                this.currentIndex = 0;
                this.isShuffled = true;
            }

            return this.isShuffled;
        }

        setRepeat(mode) {
            if (['off', 'queue', 'one'].includes(mode)) {
                this.repeatMode = mode;
            }
        }
    }

    window.FavoriteAudioQueue = AudioQueue;
})(window);

