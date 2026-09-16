(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	blocks.registerBlockType('picky-plate/box-builder', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps({ className: 'ppbb-editor-preview' });

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Builder layout', 'picky-plate-box-builder'), initialOpen: true },
						el(RangeControl, { label: __('Desktop column target', 'picky-plate-box-builder'), help: __('Responsive steps happen automatically as the builder narrows.', 'picky-plate-box-builder'), min: 2, max: 5, value: a.desktopColumns, onChange: function (v) { set({ desktopColumns: v }); } }),
						el(ToggleControl, { label: __('Show category filters', 'picky-plate-box-builder'), checked: a.showFilters, onChange: function (v) { set({ showFilters: v }); } }),
						el(ToggleControl, { label: __('Enable product details drawer', 'picky-plate-box-builder'), checked: a.showDetails, onChange: function (v) { set({ showDetails: v }); } })
					)
				),
				el(
					'div',
					blockProps,
					el('strong', null, __('Mix & Match Box Builder', 'picky-plate-box-builder')),
					el('p', null, __('This block is optional placement only. Core design defaults live in WooCommerce → Box Builder, with per-product overrides in Product data → Box Builder Design.', 'picky-plate-box-builder')),
					el('p', null, __('Desktop target: ', 'picky-plate-box-builder') + a.desktopColumns + ' · ' + __('responsive steps automatic', 'picky-plate-box-builder'))
				)
			);
		},
		save: function () { return null; }
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
