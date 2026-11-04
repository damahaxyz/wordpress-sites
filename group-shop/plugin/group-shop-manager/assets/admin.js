document.addEventListener('input', (event) => {
    if (!event.target.matches('.gsm-filter')) return;
    const query = event.target.value.trim().toLocaleLowerCase();
    event.target.closest('.gsm-picker').querySelectorAll('.gsm-choice').forEach((row) => {
        row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
    });
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('.gsm-move');
    if (!button) return;
    const item = button.closest('li');
    if (button.dataset.direction === 'up' && item.previousElementSibling) item.previousElementSibling.before(item);
    if (button.dataset.direction === 'down' && item.nextElementSibling) item.nextElementSibling.after(item);
});

// Each editor gets its own same-origin document and native media cache/context.
const storeDialog = document.querySelector('.gsm-store-dialog');
if (storeDialog && typeof storeDialog.showModal === 'function') {
    const editor = storeDialog.querySelector('iframe');
    let trigger;
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-gsm-store-dialog]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        trigger = link;
        document.getElementById('gsm-store-dialog-title').textContent = link.dataset.gsmStoreDialog;
        editor.title = link.dataset.gsmStoreDialog;
        editor.src = link.href;
        storeDialog.showModal();
    });
    storeDialog.querySelector('.gsm-dialog-close').addEventListener('click', () => storeDialog.close());
    storeDialog.addEventListener('close', () => {
        editor.removeAttribute('src');
        if (trigger) trigger.focus();
    });
    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.source !== editor.contentWindow || event.data !== 'gsm-store-saved') return;
        // Reload the list after a successful native admin-post redirect; notice remains server-side.
        window.location.reload();
    });
}
if (window.parent !== window && document.querySelector('[data-gsm-store-saved]')) {
    window.parent.postMessage('gsm-store-saved', window.location.origin);
}

// Keep the native media query/upload/edit handlers; add explicit store authorization.
if (typeof gsmMedia !== 'undefined' && window.wp && wp.media) {
    const context = {gsm_media_store: gsmMedia.store, gsm_media_nonce: gsmMedia.nonce};
    const nativePost = wp.media.post;
    wp.media.post = function (action, data) {
        if (typeof action === 'object') {
            action = Object.assign({}, action, context);
        } else {
            data = Object.assign({}, data || {}, context);
        }
        return nativePost.call(this, action, data);
    };
    // wp.media.editor.send.attachment bypasses wp.media.ajax and uses wp.ajax.send directly.
    // Without this, identical attachment IDs can insert an image from the main store.
    const nativeSend = wp.ajax.send;
    wp.ajax.send = function (action, options) {
        const name = typeof action === 'object' ? (action.data || {}).action : action;
        if (name === 'send-attachment-to-editor') {
            if (typeof action === 'object') {
                action = Object.assign({}, action, {data: Object.assign({}, action.data || {}, context)});
            } else {
                options = Object.assign({}, options || {}, {data: Object.assign({}, (options || {}).data || {}, context)});
            }
        }
        return nativeSend.call(this, action, options);
    };
    const originalAjax = wp.media.ajax;
    wp.media.ajax = function (action, options) {
        if (typeof action === 'object') {
            action = Object.assign({}, action, {data: Object.assign({}, action.data || {}, context)});
        } else {
            options = Object.assign({}, options || {}, {data: Object.assign({}, (options || {}).data || {}, context)});
        }
        return originalAjax.call(this, action, options);
    };
    if (wp.Uploader && wp.Uploader.defaults) {
        wp.Uploader.defaults.multipart_params = Object.assign({}, wp.Uploader.defaults.multipart_params || {}, context);
    }
    let frame;
    const container = document.querySelector('.gsm-logo-picker');
    if (container) {
        const input = container.querySelector('input[name="logo_id"]');
        const preview = container.querySelector('.gsm-logo-preview');
        const remove = container.querySelector('.gsm-logo-remove');
        container.querySelector('.gsm-logo-select').addEventListener('click', () => {
            if (!frame) {
                frame = wp.media({title: '选择当前店铺 Logo', button: {text: '使用此图片'}, library: {type: 'image'}, multiple: false});
                frame.on('select', () => {
                    const image = frame.state().get('selection').first().toJSON();
                    input.value = image.id;
                    preview.src = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
                    preview.hidden = false;
                    remove.hidden = false;
                });
            }
            frame.open();
        });
        remove.addEventListener('click', () => {
            input.value = '0'; preview.removeAttribute('src'); preview.hidden = true; remove.hidden = true;
        });
    }
}

// Reuse native media with the same explicitly authorized store for every product/variation.
if (typeof gsmMedia !== 'undefined' && window.wp && wp.media) {
    document.querySelectorAll('.gsm-image-picker').forEach((picker) => {
        const input = picker.querySelector('input[type="hidden"]');
        const previews = picker.querySelector('.gsm-image-previews');
        const multiple = picker.dataset.multiple === '1';
        let images = JSON.parse(previews.dataset.images || '[]');
        let frame;
        const render = (changed = true) => {
            previews.replaceChildren();
            images.forEach((item, index) => {
                const figure = document.createElement('figure');
                const image = document.createElement('img');
                image.src = item.url; image.alt = item.alt || '商品图片';
                figure.append(image);
                const caption = document.createElement('figcaption');
                caption.textContent = multiple ? (index === 0 ? '主图' : '相册 ' + index) : (picker.dataset.imageLabel || '图片');
                figure.append(caption);
                const action = (label, fn, disabled = false) => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'button button-small';
                    button.textContent = label; button.disabled = disabled;
                    button.setAttribute('aria-label', label + '第 ' + (index + 1) + ' 张图片');
                    button.addEventListener('click', fn); figure.append(button);
                };
                if (multiple) {
                    action('前移', () => { [images[index - 1], images[index]] = [images[index], images[index - 1]]; render(); }, index === 0);
                    action('后移', () => { [images[index + 1], images[index]] = [images[index], images[index + 1]]; render(); }, index === images.length - 1);
                }
                action('移除', () => { images.splice(index, 1); render(); });
                previews.append(figure);
            });
            input.value = images.map(item => item.id).join(',');
            if (changed) input.dispatchEvent(new Event('change', {bubbles: true}));
        };
        picker.querySelector('.gsm-images-select').addEventListener('click', () => {
            if (!frame) {
                frame = wp.media({title: '选择当前店铺' + (picker.dataset.imageLabel || '商品图片'), button: {text: '使用所选图片'}, library: {type: 'image'}, multiple});
                frame.on('select', () => {
                    const selected = frame.state().get('selection').toJSON().map(item => ({id: item.id, url: item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url, alt: item.alt || item.title}));
                    images = multiple ? images.concat(selected.filter(item => !images.some(existing => existing.id === item.id))).slice(0, 100) : selected.slice(0, 1);
                    render();
                });
            }
            frame.open();
        });
        picker.querySelector('.gsm-images-clear').addEventListener('click', () => { images = []; render(); });
        render(false);
    });
}
const productEditor = document.querySelector('.gsm-product-editor form, .gsm-collection-editor form');
if (productEditor) {
    let dirty = false;
    let submitting = false;
    productEditor.addEventListener('input', () => { dirty = true; });
    productEditor.addEventListener('change', () => { dirty = true; });
    productEditor.addEventListener('submit', () => {
        submitting = true;
        if (window.tinymce) tinymce.triggerSave();
    });
    window.addEventListener('beforeunload', (event) => {
        const richDirty = window.tinymce && tinymce.editors.some(editor => editor.isDirty());
        if (!submitting && (dirty || richDirty)) { event.preventDefault(); event.returnValue = ''; }
    });
    const apply = (field, value) => productEditor.querySelectorAll('[data-price="' + field + '"]').forEach(input => {
        input.value = value; input.dispatchEvent(new Event('input', {bubbles: true}));
    });
    const applyButton = productEditor.querySelector('.gsm-apply-prices');
    if (applyButton) {
        applyButton.addEventListener('click', () => {
            let count = 0;
            productEditor.querySelectorAll('[data-gsm-bulk]').forEach(input => {
                if (input.value.trim() !== '') { apply(input.dataset.gsmBulk, input.value.trim()); count++; }
            });
            productEditor.querySelector('.gsm-bulk-feedback').textContent = count ? '已填入本页，保存后生效。' : '请先填写要应用的价格。';
        });
        productEditor.querySelector('.gsm-clear-sales').addEventListener('click', () => {
            apply('sale_price', ''); productEditor.querySelector('.gsm-bulk-feedback').textContent = '已清空本页促销价，保存后生效。';
        });
    }
}

const collectionEditor = document.querySelector('.gsm-collection-editor');
if (collectionEditor) {
    const mode = collectionEditor.querySelector('select[name="mode"]');
    const ruleFields = collectionEditor.querySelector('.gsm-collection-rule-fields');
    const showMode = () => { ruleFields.hidden = mode.value === 'manual'; };
    mode.addEventListener('change', showMode); showMode();
}
if (typeof gsmCollectionSearch !== 'undefined') {
    document.querySelectorAll('.gsm-product-picker').forEach(picker => {
        const selected = picker.querySelector('.gsm-selected-products');
        const results = picker.querySelector('.gsm-product-results');
        const status = picker.querySelector('.gsm-search-status');
        const search = picker.querySelector('input[type="search"]');
        const count = picker.querySelector('.gsm-selection-count');
        let timer, controller;
        const selectedIds = () => Array.from(selected.querySelectorAll('input:checked')).map(input => Number(input.value));
        const refresh = () => {
            const ids = selectedIds(); count.textContent = ids.length;
            results.querySelectorAll('[data-select-product]').forEach(button => {
                button.disabled = ids.includes(Number(button.dataset.selectProduct));
                button.textContent = button.disabled ? '已选入' : '选入';
            });
        };
        selected.addEventListener('change', event => {
            if (event.target.matches('input[type="checkbox"]') && !event.target.checked) { event.target.closest('li').remove(); refresh(); }
        });
        const choose = item => {
            if (selectedIds().includes(item.id)) return;
            if (selectedIds().length >= 200) { status.textContent = '每组选品最多 200 件，请先移除一些商品。'; return; }
            const row = document.createElement('li'); row.dataset.product = item.id;
            const label = document.createElement('label');
            const input = document.createElement('input'); input.type = 'checkbox'; input.name = picker.dataset.name + '[]'; input.value = item.id; input.checked = true;
            const image = document.createElement('img'); image.src = item.image; image.alt = '';
            const text = document.createElement('span'); text.textContent = item.name;
            const small = document.createElement('small'); small.textContent = '#' + item.id + ' · ' + item.status; text.append(small);
            label.append(input, image, text); row.append(label);
            if (picker.dataset.priority === '1') {
                [['up','↑'],['down','↓']].forEach(([direction, arrow]) => {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'button gsm-move'; button.dataset.direction = direction; button.textContent = arrow; button.setAttribute('aria-label', direction === 'up' ? '上移' : '下移'); row.append(button);
                });
            }
            selected.append(row); refresh(); input.dispatchEvent(new Event('change', {bubbles: true}));
        };
        const lookup = async () => {
            if (controller) controller.abort(); controller = new AbortController();
            status.textContent = '正在搜索当前店铺商品…';
            try {
                const response = await fetch(gsmCollectionSearch.url, {method: 'POST', credentials: 'same-origin', signal: controller.signal, body: new URLSearchParams({action: 'gsm_collection_products', store: gsmCollectionSearch.store, nonce: gsmCollectionSearch.nonce, search: search.value.trim()})});
                const payload = await response.json();
                if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message || '商品搜索失败，请重试。');
                results.replaceChildren();
                payload.data.forEach(item => {
                    const row = document.createElement('div'); row.className = 'gsm-product-result';
                    const image = document.createElement('img'); image.src = item.image; image.alt = '';
                    const text = document.createElement('span'); text.textContent = item.name;
                    const small = document.createElement('small'); small.textContent = '#' + item.id + ' · ' + item.status; text.append(small);
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.dataset.selectProduct = item.id; button.addEventListener('click', () => choose(item));
                    row.append(image, text, button); results.append(row);
                });
                refresh(); status.textContent = payload.data.length ? '显示 ' + payload.data.length + ' 个结果（每次最多 25 个，可缩小关键词）。' : '未找到商品，请更换名称或编号。';
            } catch (error) { if (error.name !== 'AbortError') { status.textContent = error.message; } }
        };
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(lookup, 300); });
        search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); lookup(); } });
        picker.querySelector('.gsm-browse-products').addEventListener('click', () => { clearTimeout(timer); lookup(); });
        refresh();
    });
}
