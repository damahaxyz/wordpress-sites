/* Show only fields whose edits are effective for this product and variation. */
jQuery(function ($) {
    'use strict';
    const hidden = 'gsm-field-hidden';
    const box = (selector) => $(selector).closest('.form-field, .form-row');
    const toggle = (nodes, hide) => nodes.toggleClass(hidden, !!hide);
    const root = $('#woocommerce-product-data');
    const stock = '[name="_manage_stock"],[name="_stock"],[name="_stock_status"],[name="_backorders"],[name="_low_stock_amount"],[name^="variable_stock["],[name^="variable_stock_status"],[name^="variable_backorders"],[name^="variable_low_stock_amount"]';
    function stockFields() { toggle(box(stock), true); toggle($('[name^="variable_manage_stock"]').closest('label'), true); }
    stockFields();
    toggle($('.inline-edit-row [name=post_name]').closest('label'),true);
    // A list filter controls both the visible result and the signed admin context.
    $('#gsm-product-kind').on('change', function () { if (this.value === 'base') $('#gsm-brand-filter').val('0'); });
    if (!root.length) return;
    // Keep classic Woo AJAX on this editor's brand even if another tab switches scope.
    $.ajaxPrefilter(function (options) {
        if (!(options.url || '').includes('admin-ajax.php') || typeof options.data !== 'string') return;
        const data=new URLSearchParams(options.data), action=data.get('action') || '';
        if (action.startsWith('woocommerce_') && /product|variation|attribute/.test(action)) { data.set('gsm_brand',String(gsmNativeProduct.brand)); options.data=data.toString(); }
    });
    const initial = (gsmNativeProduct.overrides || []).slice().sort().join(',');
    const parentFields = {
        name: $('#titlediv'), description: $('#postdivrich'), short_description: $('#postexcerpt'),
        images: $('#postimagediv,#woocommerce-product-images'),
        price: box('#_regular_price,#_sale_price').add($('.sale_price_dates_fields'))
    };
    function overrides() { return $('[name="gsm_product_overrides[]"]:checked').map(function () { return this.value; }).get(); }
    function variations(linked, parent) {
        toggle($('.generate_variations,.add_variation_manually,.toolbar-variations-defaults,.add-variation-container'), linked);
        $('.variation_actions').each(function () {
            const select=$(this);
            if (!select.data('gsm-original-options')) select.data('gsm-original-options',select.html());
            if (select.data('gsm-linked')!==linked) {
                select.html(select.data('gsm-original-options'));
                select.find('option').each(function () {
                    if (/stock/.test(this.value) || (linked && !['bulk_actions','toggle_enabled'].includes(this.value))) $(this).remove();
                });
                select.find('optgroup').each(function () { if (!this.children.length) $(this).remove(); });
                select.data('gsm-linked',linked);
            }
        });
        $('.woocommerce_variation').each(function () {
            const row = $(this); const control = row.find('[data-gsm-variation]');
            if (!linked || !control.length) return;
            const own = control.find('input[type=checkbox]:checked').map(function () { return this.value; }).get();
            ['price','images','description'].forEach(field => toggle(control.find('[data-gsm-variation-toggle="'+field+'"]'), parent.includes(field)));
            toggle(row.find('.remove_variation'), true);
            const specs = row.find('h3 select[name^="attribute_"]');
            if (!row.find('.gsm-variation-spec').length && specs.length) {
                $('<span class="gsm-variation-spec">').text(specs.map(function () { return $(this).find('option:selected').text(); }).get().join(' / ')).appendTo(row.find('h3'));
            }
            toggle(specs, true);
            toggle(row.find('input[name^="variable_is_virtual"],input[name^="variable_is_downloadable"]').closest('label'), true);
            const common = row.find('[name^="variable_weight"],[name^="variable_length"],[name^="variable_width"],[name^="variable_height"],[name^="variable_tax_class"],[name^="variable_shipping_class"],[name^="variable_download_limit"],[name^="variable_download_expiry"]');
            toggle(common.closest('.form-row,.form-field'), true);
            toggle(row.find('.show_if_variation_downloadable,.downloadable_files'), true);
            toggle(row.find('.upload_image'), !parent.includes('images') && !own.includes('images'));
            toggle(row.find('[name^="variable_regular_price"],[name^="variable_sale_price"]').closest('.form-row'), !parent.includes('price') && !own.includes('price'));
            toggle(row.find('.sale_price_dates_fields'), !parent.includes('price') && !own.includes('price'));
            toggle(row.find('[name^="variable_description"]').closest('.form-row'), !parent.includes('description') && !own.includes('description'));
        });
    }
    function apply() {
        const brand = Number($('[name=gsm_product_brand]').val());
        const linked = brand > 0 && Number($('[name=gsm_base_product]').val()) > 0;
        const over = overrides();
        const presentationInherited = linked && !over.includes('presentation');
        $('.trovesia-presentation-fields input,.trovesia-presentation-fields textarea').prop('disabled',presentationInherited);
        $('.trovesia-presentation-fields').toggleClass('gsm-presentation-inherited',presentationInherited);
        toggle($('#edit-slug-box .edit-slug,#slugdiv'),true);
        toggle($('#edit-slug-box'),brand===0);
        toggle($('.gsm-override-options'), !linked);
        toggle($('.gsm-base-selector'), brand === 0);
        toggle($('.gsm-target-brands'), brand !== 0);
        Object.entries(parentFields).forEach(([field,nodes]) => toggle(nodes, linked && !over.includes(field)));
        toggle($('#product_catdiv,#tagsdiv-product_tag'), linked);
        toggle($('#product-type').closest('label'), linked);
        toggle($('#_virtual,#_downloadable').closest('label'), linked);
        toggle($('[href="#shipping_product_data"],[href="#product_attributes"]').closest('li'), linked);
        toggle($('#shipping_product_data,#product_attributes'), linked);
        toggle(box('#_tax_status,#_tax_class,#_sold_individually,#_purchase_note,#_download_limit,#_download_expiry'), linked);
        toggle($('.downloadable_files'), linked);
        const generalHasFields=$('#general_product_data').find('input,select,textarea').filter(function () {
            return this.type!=='hidden' && getComputedStyle(this).display!=='none' && !$(this).parentsUntil('#general_product_data').toArray().some(parent=>getComputedStyle(parent).display==='none');
        }).length>0;
        toggle($('[href="#general_product_data"]').closest('li'), linked && !generalHasFields);
        if (root.find('.product_data_tabs li.active').hasClass(hidden)) root.find('a[href="#gsm_versions_panel"]').trigger('click');
        if (!$('#gsm-editing-policy').length) $('<div id="gsm-editing-policy" class="gsm-inheritance-notice">').insertAfter($('#titlediv'));
        const note = $('#gsm-editing-policy');
        note.empty();
        if (linked) {
            $('<p>').text('当前品牌商品：'+$('#title').val()).appendTo(note);
            $('<p>').text('分类、标签、规格、物流属性、税务及下载资料由公共商品维护。勾选“单独设置”后，才显示对应编辑字段；发布状态、排序等仍由当前品牌维护。').appendTo(note);
            const selected = $('[name=gsm_base_product] option:selected').text() || gsmNativeProduct.baseLabel;
            $('<p>').text('资料来源：'+selected).appendTo(note);
        }
        toggle(note, !linked);
        const dirty = linked && $('#product-type').val()==='variable' && over.slice().sort().join(',') !== initial;
        if (!$('#gsm-variation-save-first').length) $('<p id="gsm-variation-save-first" class="gsm-inheritance-notice">请先更新商品保存继承选项，再编辑变体。</p>').prependTo($('#variable_product_options_inner'));
        toggle($('#gsm-variation-save-first'), !dirty);
        toggle($('.woocommerce_variations,.save-variation-changes,.cancel-variation-changes'), dirty);
        variations(linked,over); stockFields();
    }
    $(document).on('change', '[name="gsm_product_overrides[]"],[name=gsm_base_product],[name=gsm_product_brand],.gsm-variation-inheritance input', apply);
    $(document.body).on('woocommerce_variations_loaded woocommerce_variations_added woocommerce_variations_saved', apply);
    apply();
    if (gsmNativeProduct.linked) root.find('a[href="#gsm_versions_panel"]').trigger('click');
});
