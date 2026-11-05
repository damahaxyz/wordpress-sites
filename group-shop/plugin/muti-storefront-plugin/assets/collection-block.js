(function (wp) {
    const el = wp.element.createElement;
    const {InspectorControls, useBlockProps} = wp.blockEditor;
    const {PanelBody, SelectControl, RangeControl, ToggleControl, Placeholder, Disabled} = wp.components;
    const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
    wp.blocks.registerBlockType('group-shop/collection', {
        apiVersion: 3, title: '商品集合', icon: 'screenoptions', category: 'woocommerce',
        description: '使用当前店铺已保存的商品集合，为首页模块和专题页面展示商品。',
        attributes: {
            collectionId: {type: 'integer', default: 0}, perPage: {type: 'integer', default: 8}, columns: {type: 'integer', default: 4},
            paginate: {type: 'boolean', default: false}, showTitle: {type: 'boolean', default: true}, showDescription: {type: 'boolean', default: true}, showImage: {type: 'boolean', default: false}
        },
        supports: {html: false},
        edit: function (props) {
            const attrs = props.attributes;
            const options = [{label: '请选择集合', value: 0}].concat((window.gsmCollectionBlocks || {}).items || []);
            if (attrs.collectionId && !options.some(item => item.value === attrs.collectionId)) options.push({label: '当前集合已停用或删除，请重新选择', value: attrs.collectionId});
            const selector = el(SelectControl, {label: '选择商品集合', value: attrs.collectionId, options, onChange: value => props.setAttributes({collectionId: Number(value)})});
            return el('div', useBlockProps({className: 'woocommerce'}),
                el(InspectorControls, {}, el(PanelBody, {title: '集合展示'}, selector,
                    el(RangeControl, {label: '每页商品数', value: attrs.perPage, min: 1, max: 48, onChange: value => props.setAttributes({perPage: value})}),
                    el(RangeControl, {label: '商品列数', value: attrs.columns, min: 1, max: 6, onChange: value => props.setAttributes({columns: value})}),
                    ...[['paginate','显示分页'],['showTitle','显示集合标题'],['showDescription','显示集合描述'],['showImage','显示集合图片']].map(([key,label]) => el(ToggleControl, {key, label, checked: attrs[key], onChange: value => props.setAttributes({[key]: value})}))
                )),
                attrs.collectionId ? el(Disabled, {}, el(ServerSideRender, {block: 'group-shop/collection', attributes: attrs})) : el(Placeholder, {icon: 'screenoptions', label: '商品集合', instructions: '选择当前店铺的集合。还没有集合时，先在多店铺管理中创建并启用。'}, selector)
            );
        },
        save: function () { return null; }
    });
})(window.wp);
