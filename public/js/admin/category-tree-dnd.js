(() => {
    if (window.__categoryTreeDndReady) {
        return;
    }

    window.__categoryTreeDndReady = true;

    let draggingId = null;
    let activeRoot = null;
    let dropTarget = null;
    let dropMode = null;
    let ghost = null;

    const dropClasses = ['is-drop-target', 'is-drop-before', 'is-drop-after'];

    const clearDropTarget = () => {
        if (dropTarget) {
            dropTarget.classList.remove(...dropClasses);
            dropTarget = null;
        }

        dropMode = null;
    };

    const findDropTarget = (element) => {
        if (! element) {
            return null;
        }

        if (element.classList?.contains('category-tree-root-dropzone')) {
            return element;
        }

        return element.closest?.('[data-drop-category-id]') ?? null;
    };

    const resolveParentId = (target) => {
        if (! target || target.classList.contains('category-tree-root-dropzone')) {
            return null;
        }

        const value = target.dataset.dropCategoryId;

        if (! value) {
            return null;
        }

        return Number.parseInt(value, 10);
    };

    const resolveAnchorId = (target) => {
        const value = target?.dataset?.dropCategoryId;

        if (! value) {
            return null;
        }

        return Number.parseInt(value, 10);
    };

    const getComponent = (root) => {
        const id = root?.dataset?.livewireId;

        if (! id || typeof Livewire === 'undefined') {
            return null;
        }

        return Livewire.find(id);
    };

    const positionGhost = (event) => {
        if (! ghost) {
            return;
        }

        ghost.style.transform = `translate(${event.clientX + 14}px, ${event.clientY + 10}px)`;
    };

    const cleanup = () => {
        draggingId = null;
        activeRoot?.classList.remove('is-dragging');
        activeRoot = null;
        clearDropTarget();
        ghost?.remove();
        ghost = null;
    };

    const resolveDropMode = (target, event) => {
        if (! target || target.classList.contains('category-tree-root-dropzone')) {
            return 'root';
        }

        const categoryId = resolveAnchorId(target);

        if (categoryId === draggingId) {
            return null;
        }

        const rect = target.getBoundingClientRect();
        const relativeY = (event.clientY - rect.top) / rect.height;

        if (relativeY < 0.28) {
            return 'before';
        }

        if (relativeY > 0.72) {
            return 'after';
        }

        return 'child';
    };

    const highlightDropTarget = (event) => {
        if (ghost) {
            ghost.style.visibility = 'hidden';
        }

        const under = document.elementFromPoint(event.clientX, event.clientY);

        if (ghost) {
            ghost.style.visibility = 'visible';
        }

        const target = findDropTarget(under);
        const mode = target ? resolveDropMode(target, event) : null;

        if (! target || ! mode) {
            clearDropTarget();

            return;
        }

        const className = mode === 'before'
            ? 'is-drop-before'
            : mode === 'after'
                ? 'is-drop-after'
                : mode === 'child'
                    ? 'is-drop-target'
                    : 'is-drop-target';

        if (dropTarget !== target || dropMode !== mode) {
            clearDropTarget();
            dropTarget = target;
            dropMode = mode;
            dropTarget.classList.add(className);
        }
    };

    document.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest?.('.category-tree-drag-handle');

        if (! handle || event.button !== 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        draggingId = Number.parseInt(handle.dataset.categoryId, 10);
        activeRoot = handle.closest('.category-tree-dnd');
        activeRoot?.classList.add('is-dragging');

        try {
            handle.setPointerCapture(event.pointerId);
        } catch (error) {
            // Ignore browsers that reject capture.
        }

        const label = handle.closest('.category-tree-summary')?.querySelector('.category-tree-name strong')?.textContent?.trim();

        ghost = document.createElement('div');
        ghost.className = 'category-tree-drag-ghost';
        ghost.textContent = label || 'Категорія';
        document.body.appendChild(ghost);
        positionGhost(event);
    }, true);

    document.addEventListener('pointermove', (event) => {
        if (draggingId === null) {
            return;
        }

        event.preventDefault();
        positionGhost(event);
        highlightDropTarget(event);
    }, true);

    document.addEventListener('pointerup', async (event) => {
        if (draggingId === null) {
            return;
        }

        event.preventDefault();

        const categoryId = draggingId;
        const root = activeRoot;
        const target = dropTarget;
        const mode = dropMode;
        const component = getComponent(root);

        cleanup();

        if (! target || ! component || ! mode) {
            return;
        }

        if (mode === 'root') {
            await component.call('moveCategory', categoryId, null);

            return;
        }

        const anchorCategoryId = resolveAnchorId(target);

        if (! anchorCategoryId) {
            return;
        }

        if (mode === 'child') {
            await component.call('moveCategory', categoryId, anchorCategoryId);

            return;
        }

        await component.call('reorderCategory', categoryId, anchorCategoryId, mode);
    }, true);

    document.addEventListener('pointercancel', cleanup, true);
})();
