/* global jQuery, ppbbSettings, wc_mnm_price_format */
(function ($) {
	'use strict';

	var detailCache = {};
	var focusBeforeDialog = null;

	function text(value) {
		return $('<div>').html(value || '').text().trim();
	}

	function titleFromSlug(slug) {
		return slug.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function formatPrice(number) {
		if (typeof window.wc_mnm_price_format === 'function') {
			return window.wc_mnm_price_format(Number(number || 0));
		}
		try {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: ppbbSettings.currency || 'USD' }).format(Number(number || 0));
		} catch (e) {
			return '$' + Number(number || 0).toFixed(2);
		}
	}

	function announce($mount, message) {
		$mount.find('.ppbb-live-region').text('');
		setTimeout(function () { $mount.find('.ppbb-live-region').text(message); }, 10);
	}

	function hideMountUnit($mount) {
		var $unit = $mount.closest('.ppbb-unit');
		($unit.length ? $unit : $mount).hide();
	}

	function getMount(productId) {
		var selector = '.ppbb-mount[data-ppbb-product="' + productId + '"]';
		var $manuals = $(selector + '[data-ppbb-auto="0"]');
		if ($manuals.length) {
			var $manual = $manuals.first();
			$manuals.slice(1).each(function () { hideMountUnit($(this)); });
			$(selector + '[data-ppbb-auto="1"]').each(function () { hideMountUnit($(this)); });
			return $manual;
		}
		var $mounts = $(selector);
		$mounts.slice(1).each(function () { hideMountUnit($(this)); });
		return $mounts.first();
	}

	function productDataFromNative($row) {
		var id = parseInt($row.data('mnm_item_id'), 10);
		var $qty = $row.find('input.mnm-quantity, input.qty').first();
		var image = $row.find('.product-thumbnail img, .mnm_child_product_images img').first().attr('src') || '';
		var largeImage = $row.find('.product-thumbnail img, .mnm_child_product_images img').first().attr('data-large_image') || image;
		var name = text($row.find('.woocommerce-loop-product__title, .product-details h4, .product-details .product-title').first().html()) || ('Product ' + id);
		var priceHtml = $row.find('.product-details .price').first().html() || '';
		var classes = ($row.attr('class') || '').split(/\s+/);
		var categories = classes.filter(function (c) { return c.indexOf('product_cat-') === 0; }).map(function (c) { return c.replace('product_cat-', ''); });
		var maxStock = parseInt($row.attr('data-max_stock'), 10);

		return {
			id: id,
			$row: $row,
			$qty: $qty,
			name: name,
			image: image,
			largeImage: largeImage,
			priceHtml: priceHtml,
			categories: categories,
			max: isNaN(parseFloat($qty.attr('max'))) ? null : parseFloat($qty.attr('max')),
			min: isNaN(parseFloat($qty.attr('min'))) ? 0 : parseFloat($qty.attr('min')),
			step: isNaN(parseFloat($qty.attr('step'))) ? 1 : parseFloat($qty.attr('step')),
			inStock: !$row.hasClass('outofstock') && (isNaN(maxStock) || maxStock !== 0)
		};
	}

	function getQty(item) {
		var qty = parseFloat(item.$qty.val());
		return isNaN(qty) ? 0 : qty;
	}

	function setQty(item, next) {
		var min = item.min || 0;
		var max = item.max;
		next = Math.max(min, next);
		if (max !== null) {
			next = Math.min(max, next);
		}
		item.$qty.val(next > 0 ? next : '').trigger('change');
	}

	function getCategoryFilters(items) {
		var counts = {};
		items.forEach(function (item) {
			item.categories.forEach(function (cat) { counts[cat] = (counts[cat] || 0) + 1; });
		});
		return Object.keys(counts).filter(function (cat) {
			// Hide broad taxonomy buckets shared by most items; keep differentiating categories.
			return counts[cat] < items.length * 0.72;
		}).sort();
	}

	function buildCard(item, options) {
		var $card = $('<article class="ppbb-card" data-product-id="' + item.id + '"></article>');
		if (!item.inStock) { $card.addClass('is-out-of-stock'); }

		// Keep the visual media frame separate from the clickable button. Themes often
		// apply aggressive rounded/pill styles to buttons; using a neutral wrapper means
		// image shape controls remain deterministic regardless of the active theme.
		var $media = $('<div class="ppbb-card-media"></div>')
			.append($('<img class="ppbb-card-image" loading="lazy">').attr({ src: item.image, alt: item.name }));

		if (options.showDetails) {
			$media.addClass('is-clickable').append(
				$('<button type="button" class="ppbb-card-image-button"></button>')
					.attr('aria-label', (ppbbSettings.i18n.details || 'Details') + ': ' + item.name)
			);
		}

		var $body = $('<div class="ppbb-card-body"></div>');
		$body.append($('<h3 class="ppbb-card-title"></h3>').text(item.name));
		$body.append($('<div class="ppbb-card-price"></div>').html(item.priceHtml));

		if (options.showDetails) {
			$body.append($('<button type="button" class="ppbb-details-link"></button>').text(ppbbSettings.i18n.details || 'Details'));
		}

		var $qty = $('<div class="ppbb-qty" role="group"></div>').attr('aria-label', item.name + ' quantity');
		var $minus = $('<button type="button" class="ppbb-qty-button ppbb-minus" aria-label="Reduce quantity">−</button>');
		var $value = $('<output class="ppbb-qty-value" aria-live="polite">0</output>');
		var $plus = $('<button type="button" class="ppbb-qty-button ppbb-plus" aria-label="Increase quantity">+</button>');
		$qty.append($minus, $value, $plus);
		$body.append($qty);

		$card.append($media, $body);
		return $card;
	}

	function buildBaseUI($mount, items, api, options) {
		$mount.empty();
		if ($mount[0] && $mount[0].style) {
			$mount[0].style.setProperty('--ppbb-cols-desktop', String(options.desktopColumns));
			if (options.titleSize) { $mount[0].style.setProperty('--ppbb-title-size', options.titleSize); }
			else { $mount[0].style.removeProperty('--ppbb-title-size'); }
			if (options.tileGap) { $mount[0].style.setProperty('--ppbb-tile-gap', options.tileGap); }
			else { $mount[0].style.removeProperty('--ppbb-tile-gap'); }
		}
		$mount.removeClass(function (index, className) {
			return (className.match(/(^|\s)ppbb-image-style-\S+|(^|\s)ppbb-grid-target-\d+/g) || []).join(' ');
		}).addClass('ppbb-image-style-' + options.imageStyle + ' ppbb-grid-target-' + options.desktopColumns);

		var $live = $('<div class="ppbb-live-region screen-reader-text" aria-live="polite" aria-atomic="true"></div>');
		var $shell = $('<div class="ppbb-shell"></div>');
		var $catalog = $('<section class="ppbb-catalog"></section>');
		var $head = $('<div class="ppbb-catalog-head"></div>');
		$head.append($('<div><h2 class="ppbb-heading"></h2><p class="ppbb-instruction"></p></div>'));
		var $filters = $('<div class="ppbb-filters" role="group" aria-label="Filter products"></div>');
		var $grid = $('<div class="ppbb-grid"></div>');
		var $summary = $('<aside class="ppbb-summary" aria-label="Your box"></aside>');
		var $sidebarColumn = $('<div class="ppbb-sidebar-column"></div>');
		var $sidebarSticky = $('<div class="ppbb-sidebar-sticky"></div>');

		$catalog.append($head);
		if (options.showFilters) { $catalog.append($filters); }
		$catalog.append($grid);
		$sidebarSticky.append($summary);
		$sidebarColumn.append($sidebarSticky);
		$shell.append($catalog, $sidebarColumn);

		// Product-specific SEO/content regions are emitted server-side so they are
		// present in the initial HTML. Once the builder initializes, move each one
		// into its intended visual column without cloning or hiding the content.
		var $unit = $mount.closest('.ppbb-unit');
		var $belowMain = $unit.children('.ppbb-unit-content--below-main').first();
		var $belowSidebar = $unit.children('.ppbb-unit-content--below-sidebar').first();
		if ($belowMain.length) { $catalog.append($belowMain.detach()); }
		if ($belowSidebar.length) { $sidebarSticky.append($belowSidebar.detach()); }

		var $mobileBar = $('<button type="button" class="ppbb-mobile-bar" aria-expanded="false"><span class="ppbb-mobile-count"></span><span class="ppbb-mobile-total"></span><span class="ppbb-mobile-action"></span></button>')
			.addClass('ppbb-mobile-bar--' + options.mobileBarStyle);
		if (options.highContrastColor) { $mobileBar.css('--ppbb-high-contrast-bg', options.highContrastColor); }
		var $drawer = $('<div class="ppbb-drawer" aria-hidden="true"><div class="ppbb-drawer-backdrop"></div><section class="ppbb-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="ppbb-drawer-title"><button type="button" class="ppbb-dialog-close" aria-label="Close">×</button><div class="ppbb-drawer-content"></div></section></div>')
			.addClass('ppbb-drawer--' + options.mobileBarStyle);
		var $modal = $('<div class="ppbb-modal" aria-hidden="true"><div class="ppbb-modal-backdrop"></div><section class="ppbb-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ppbb-detail-title"><button type="button" class="ppbb-dialog-close" aria-label="Close">×</button><div class="ppbb-modal-content"></div></section></div>');

		// Keep the fixed mobile controls/dialogs outside Nectarblocks/Woo wrappers.
		// Some theme/footer containers create stacking contexts that can otherwise
		// cover or clip fixed UI near the bottom of the viewport.
		var overlayId = String($mount.attr('data-ppbb-product') || 'product');
		$('body').find('[data-ppbb-overlay-for="' + overlayId + '"]').remove();
		$mobileBar.attr('data-ppbb-overlay-for', overlayId);
		$drawer.attr('data-ppbb-overlay-for', overlayId);
		$modal.attr('data-ppbb-overlay-for', overlayId);
		$mount.append($live, $shell);
		$('body').append($mobileBar, $drawer, $modal);

		items.forEach(function (item) { $grid.append(buildCard(item, options)); });

		// Apply explicit design overrides to the actual rendered elements as well as
		// via CSS variables. This prevents theme typography/grid rules from silently
		// winning and makes the global/per-product controls deterministic.
		if (options.titleSize && $grid[0]) {
			$grid.find('.ppbb-card-title').each(function () { this.style.setProperty('font-size', options.titleSize, 'important'); });
		}
		if (options.tileGap && $grid[0]) {
			$grid[0].style.setProperty('gap', options.tileGap, 'important');
			$grid[0].style.setProperty('row-gap', options.tileGap, 'important');
			$grid[0].style.setProperty('column-gap', options.tileGap, 'important');
		}

		var cats = getCategoryFilters(items);
		if (options.showFilters && cats.length > 1) {
			$filters.append($('<button type="button" class="ppbb-filter is-active" data-filter="all"></button>').text(ppbbSettings.i18n.all || 'All'));
			cats.forEach(function (cat) { $filters.append($('<button type="button" class="ppbb-filter"></button>').attr('data-filter', cat).text(titleFromSlug(cat))); });
		} else {
			$filters.remove();
		}

		return {
			$mount: $mount,
			$grid: $grid,
			$summary: $summary,
			$sidebarColumn: $sidebarColumn,
			$sidebarSticky: $sidebarSticky,
			$mobileBar: $mobileBar,
			$drawer: $drawer,
			$drawerContent: $drawer.find('.ppbb-drawer-content'),
			$modal: $modal,
			$modalContent: $modal.find('.ppbb-modal-content')
		};
	}

	function progressLabel(api) {
		var count = api.get_container_size();
		var max = api.get_max_container_size();
		var min = api.get_min_container_size();
		if (max !== false) { return count + ' / ' + max; }
		if (min !== false) { return count + ' selected · min ' + min; }
		return count + ' selected';
	}

	function buildSlots(items, max, requestedColumns) {
		if (!max || max > 12) { return $(); }
		var selected = [];
		items.forEach(function (item) {
			for (var i = 0; i < getQty(item); i++) { selected.push(item); }
		});
		var columns = parseInt(requestedColumns, 10) || 0;
		if (!columns) {
			columns = max <= 7 ? max : (max <= 10 ? 5 : 6);
		}
		columns = Math.max(1, Math.min(12, columns));
		var $slots = $('<div class="ppbb-slots" aria-hidden="true"></div>').css('--ppbb-slot-cols', columns);
		for (var s = 0; s < max; s++) {
			var $slot = $('<span class="ppbb-slot"></span>');
			if (selected[s]) {
				$slot.addClass('is-filled').append($('<img>').attr({ src: selected[s].image, alt: '' }));
			}
			$slots.append($slot);
		}
		return $slots;
	}

	function getPurchaseOptions($form) {
		var options = [];
		var $dropdown = $form.find('.wcsatt-options-product-dropdown').first();
		$form.find('.wcsatt-options-product > li').each(function () {
			var $li = $(this);
			var $input = $li.find('input[type="radio"]').first();
			if (!$input.length) { return; }
			var value = String($input.val());
			var data = $input.data('custom_data') || {};
			var scheme = data.subscription_scheme || null;
			var isOneTime = value === '0';
			var discount = scheme && scheme.discount ? parseFloat(scheme.discount) : 0;
			var frequency = '';
			if (!isOneTime) {
				var $dropdownOption = $dropdown.find('option[value="' + value + '"]').first();
				frequency = $dropdownOption.length ? text($dropdownOption.text()) : text(data.dropdown_format || $li.find('.subscription-details').first().text());
			}
			var $priceNode = $li.find(isOneTime ? '.one-time-price' : '.subscription-price').first();
			options.push({
				value: value,
				isOneTime: isOneTime,
				discount: isNaN(discount) ? 0 : discount,
				frequency: frequency,
				priceHtml: $priceNode.html() || '',
				scheme: scheme,
				data: data,
				$input: $input
			});
		});
		return options;
	}

	function activePurchaseValue($form) {
		var $checked = $form.find('.wcsatt-options-product input[type="radio"]:checked').first();
		return $checked.length ? String($checked.val()) : null;
	}

	function preferredSubscriptionValue($form, subscriptionOptions) {
		var active = activePurchaseValue($form);
		if (active && active !== '0' && subscriptionOptions.some(function (option) { return option.value === active; })) {
			return active;
		}
		var dropdownValue = String($form.find('.wcsatt-options-product-dropdown').first().val() || '');
		if (dropdownValue && subscriptionOptions.some(function (option) { return option.value === dropdownValue; })) {
			return dropdownValue;
		}
		return subscriptionOptions.length ? subscriptionOptions[0].value : '';
	}

	function setPurchaseValue($form, value) {
		var $prompt = $form.find('.wcsatt-options-prompt-action-input');
		var $scheme = $form.find('.wcsatt-options-product input[type="radio"][value="' + value + '"]').first();
		if (!$scheme.length) { return; }
		if (value === '0') {
			$prompt.filter('[value="no"]').prop('checked', true).trigger('change');
			$scheme.prop('checked', true).trigger('change');
		} else {
			$prompt.filter('[value="yes"]').prop('checked', true).trigger('change');
			$form.find('.wcsatt-options-product-dropdown').val(value).trigger('change');
			$scheme.prop('checked', true).trigger('change');
		}
	}

	function purchaseDisplayData($form, api, options) {
		var oneTime = options.filter(function (option) { return option.isOneTime; })[0] || null;
		var subscriptions = options.filter(function (option) { return !option.isOneTime; });
		var activeValue = activePurchaseValue($form) || '0';
		var selectedSubscriptionValue = preferredSubscriptionValue($form, subscriptions);
		var selectedSubscription = subscriptions.filter(function (option) { return option.value === selectedSubscriptionValue; })[0] || subscriptions[0] || null;
		var total = Number(api.get_container_price('price') || 0);
		var activeSubscription = subscriptions.filter(function (option) { return option.value === activeValue; })[0] || null;
		var oneTimeTotal = total;
		var subscriptionTotal = total;

		// APFS's MNM integration may already have applied the selected percentage
		// discount to the live container price. Reconstruct the one-time total for
		// display so both purchase types can show the current configured box price.
		if (activeSubscription && activeSubscription.discount > 0 && activeSubscription.discount < 100) {
			oneTimeTotal = total / (1 - activeSubscription.discount / 100);
		}
		if (selectedSubscription && activeValue === '0' && selectedSubscription.discount > 0) {
			subscriptionTotal = total * (1 - selectedSubscription.discount / 100);
		}

		var discounts = subscriptions.map(function (option) { return option.discount || 0; });
		var commonDiscount = discounts.length && discounts.every(function (discount) { return discount === discounts[0]; }) ? discounts[0] : 0;
		var subscribeLabel = ppbbSettings.i18n.subscribe || 'Subscribe';
		if (commonDiscount > 0) {
			subscribeLabel = (ppbbSettings.i18n.subscribeSave || 'Subscribe & save %s%%').replace('%s', commonDiscount % 1 ? commonDiscount : Math.round(commonDiscount));
		}

		return {
			oneTime: oneTime,
			subscriptions: subscriptions,
			activeValue: activeValue,
			selectedSubscriptionValue: selectedSubscriptionValue,
			selectedSubscription: selectedSubscription,
			oneTimeTotal: oneTimeTotal,
			subscriptionTotal: subscriptionTotal,
			subscribeLabel: subscribeLabel
		};
	}

	function summaryMarkup(items, api, $form, isDrawer, options) {
		var count = api.get_container_size();
		var max = api.get_max_container_size();
		var min = api.get_min_container_size();
		var valid = api.get_validation_status() === 'pass';
		var total = api.get_container_price('price');
		var selected = items.filter(function (item) { return getQty(item) > 0; });
		var purchaseOptions = getPurchaseOptions($form);
		var purchaseData = purchaseDisplayData($form, api, purchaseOptions);
		var $wrap = $('<div class="ppbb-summary-inner"></div>');

		var $headingRow = $('<div class="ppbb-summary-heading-row"><h2 class="ppbb-summary-title"></h2></div>');
		$headingRow.find('h2').text(ppbbSettings.i18n.yourBox || 'Your Box');
		if (isDrawer) { $headingRow.find('h2').attr('id', 'ppbb-drawer-title'); }
		$wrap.append($headingRow);
		$wrap.append($('<div class="ppbb-progress-row"><strong class="ppbb-progress-count"></strong><span class="ppbb-progress-copy"></span></div>').find('.ppbb-progress-count').text(progressLabel(api)).end());
		$wrap.append(buildSlots(items, max, options.slotColumns));

		var $lines = $('<div class="ppbb-selected-lines"></div>');
		selected.forEach(function (item) {
			var qty = getQty(item);
			var $line = $('<div class="ppbb-selected-line" data-product-id="' + item.id + '"></div>');
			$line.append($('<img class="ppbb-selected-thumb" alt="">').attr('src', item.image));
			$line.append($('<span class="ppbb-selected-name"></span>').text(item.name));
			$line.append($('<span class="ppbb-selected-qty"></span>').text('×' + qty));
			var $controls = $('<span class="ppbb-selected-controls"></span>');
			$controls.append($('<button type="button" class="ppbb-line-minus">−</button>').attr('aria-label', 'Reduce ' + item.name + ' quantity'));
			$controls.append($('<button type="button" class="ppbb-line-plus">+</button>').attr('aria-label', 'Increase ' + item.name + ' quantity'));
			$line.append($controls);
			$lines.append($line);
		});
		if (selected.length) { $wrap.append($lines); }

		var remaining = 0;
		if (max !== false) { remaining = Math.max(0, max - count); }
		else if (min !== false) { remaining = Math.max(0, min - count); }
		var $message = $('<div class="ppbb-summary-message" aria-live="polite"></div>');
		if (remaining > 0) {
			$message.text((ppbbSettings.i18n.needMore || '%s more item%s needed').replace('%s', remaining).replace('%s', remaining === 1 ? '' : 's'));
		} else if (valid) {
			$message.text('Your box is ready.');
		}
		$wrap.append($message);

		var $total = $('<div class="ppbb-total-row"><span>Box total</span><strong class="ppbb-total"></strong></div>');
		$total.find('.ppbb-total').html(formatPrice(total));
		$wrap.append($total);

		if (purchaseData.oneTime && purchaseData.subscriptions.length) {
			var purchaseName = isDrawer ? 'ppbb-purchase-type-drawer' : 'ppbb-purchase-type-desktop';
			var oneTimeId = 'ppbb-purchase-type-' + (isDrawer ? 'drawer-' : 'desktop-') + 'one-time';
			var subscribeId = 'ppbb-purchase-type-' + (isDrawer ? 'drawer-' : 'desktop-') + 'subscribe';
			var isSubscription = purchaseData.activeValue !== '0';
			var $purchase = $('<fieldset class="ppbb-purchase"><legend>Purchase option</legend></fieldset>');

			var $oneTimeLabel = $('<label class="ppbb-purchase-option ppbb-purchase-type-option"></label>').attr('for', oneTimeId);
			var $oneTimeRadio = $('<input type="radio" class="ppbb-purchase-type-radio" value="one-time">').attr({ id: oneTimeId, name: purchaseName });
			$oneTimeRadio.prop('checked', !isSubscription);
			$oneTimeLabel.append($oneTimeRadio);
			var $oneTimeCopy = $('<span class="ppbb-purchase-copy"><strong class="ppbb-purchase-label"></strong><span class="ppbb-purchase-price"></span></span>');
			$oneTimeCopy.find('.ppbb-purchase-label').text(ppbbSettings.i18n.oneTime || 'One-time purchase');
			$oneTimeCopy.find('.ppbb-purchase-price').html(formatPrice(purchaseData.oneTimeTotal));
			$oneTimeLabel.append($oneTimeCopy);
			$purchase.append($oneTimeLabel);

			var $subscribeLabel = $('<label class="ppbb-purchase-option ppbb-purchase-type-option"></label>').attr('for', subscribeId);
			var $subscribeRadio = $('<input type="radio" class="ppbb-purchase-type-radio" value="subscribe">').attr({ id: subscribeId, name: purchaseName });
			$subscribeRadio.prop('checked', isSubscription);
			$subscribeLabel.append($subscribeRadio);
			var $subscribeCopy = $('<span class="ppbb-purchase-copy"><strong class="ppbb-purchase-label"></strong><span class="ppbb-purchase-price"></span></span>');
			$subscribeCopy.find('.ppbb-purchase-label').text(purchaseData.subscribeLabel);
			var frequencyText = purchaseData.selectedSubscription ? purchaseData.selectedSubscription.frequency : '';
			var subscribePrice = formatPrice(purchaseData.subscriptionTotal);
			$subscribeCopy.find('.ppbb-purchase-price').html(subscribePrice + (frequencyText ? ' · ' + $('<div>').text(frequencyText).html() : ''));
			$subscribeLabel.append($subscribeCopy);
			$purchase.append($subscribeLabel);

			if (purchaseData.subscriptions.length > 1) {
				var selectId = 'ppbb-frequency-' + (isDrawer ? 'drawer' : 'desktop');
				var $frequency = $('<div class="ppbb-frequency"></div>').toggleClass('is-visible', isSubscription);
				$frequency.append($('<label class="ppbb-frequency-label"></label>').attr('for', selectId).text(ppbbSettings.i18n.deliveryFrequency || 'Delivery frequency'));
				var $select = $('<select class="ppbb-frequency-select"></select>').attr('id', selectId);
				purchaseData.subscriptions.forEach(function (option) {
					$select.append($('<option></option>').attr('value', option.value).text(option.frequency || 'Subscription'));
				});
				$select.val(purchaseData.selectedSubscriptionValue);
				$frequency.append($select);
				$purchase.append($frequency);
			}

			$wrap.append($purchase);
		}

		// Optional editorial content supplied by the Mix & Match product. Keep it
		// independent of plan selection so it appears for both purchase types.
		if (options.purchaseNoteHtml) {
			$wrap.append(options.purchaseNoteHtml);
		}

		var $containerQty = $form.find('.mnm_button_wrap > .quantity input.qty, .add_to_cart_button_wrap > .quantity input.qty').first();
		// Mirror WooCommerce's native sold-individually behavior: its quantity is fixed
		// at 1 and its quantity field is hidden. Never render a duplicate visual control.
		if (!options.soldIndividually && $containerQty.length && $containerQty.attr('type') !== 'hidden' && !$containerQty.closest('.quantity').hasClass('hidden')) {
			var boxQty = parseFloat($containerQty.val()) || 1;
			var $boxQty = $('<div class="ppbb-box-qty"><span class="ppbb-box-qty-label"></span><div class="ppbb-qty"><button type="button" class="ppbb-box-minus ppbb-qty-button">−</button><output class="ppbb-box-qty-value"></output><button type="button" class="ppbb-box-plus ppbb-qty-button">+</button></div></div>');
			$boxQty.find('.ppbb-box-qty-label').text(ppbbSettings.i18n.numberBoxes || 'Number of boxes');
			$boxQty.find('.ppbb-box-qty-value').text(boxQty);
			$wrap.append($boxQty);
		}

		var $actions = $('<div class="ppbb-summary-actions"></div>');
		var buttonText = valid ? (ppbbSettings.i18n.addBox || 'Add My Box') : (isDrawer ? (ppbbSettings.i18n.continueBuilding || 'Continue Building') : (ppbbSettings.i18n.addBox || 'Add My Box'));
		var $add = $('<button type="button" class="ppbb-add button alt wp-element-button"></button>').text(buttonText);
		$add.prop('disabled', !valid && !isDrawer).toggleClass('disabled', !valid && !isDrawer);
		if (!valid && isDrawer) { $add.addClass('ppbb-continue'); }
		$actions.append($add);
		if (count > 0) { $actions.append($('<button type="button" class="ppbb-clear"></button>').text(ppbbSettings.i18n.clearBox || 'Clear box')); }
		$wrap.append($actions);
		return $wrap;
	}

	function openLayer($layer) {
		focusBeforeDialog = document.activeElement;
		$layer.find('.ppbb-drawer-panel, .ppbb-modal-panel').scrollTop(0);
		$layer.attr('aria-hidden', 'false').addClass('is-open');
		$('body').addClass('ppbb-dialog-open');
		setTimeout(function () {
			$layer.find('.ppbb-drawer-panel, .ppbb-modal-panel').scrollTop(0);
			$layer.find('.ppbb-dialog-close').first().trigger('focus');
		}, 20);
	}

	function closeLayer($layer) {
		$layer.attr('aria-hidden', 'true').removeClass('is-open');
		if (!$('.ppbb-modal.is-open, .ppbb-drawer.is-open').length) { $('body').removeClass('ppbb-dialog-open'); }
		if (focusBeforeDialog && typeof focusBeforeDialog.focus === 'function') { focusBeforeDialog.focus(); }
		focusBeforeDialog = null;
	}

	function trapFocus(e, $layer) {
		if (e.key !== 'Tab') { return; }
		var $focusable = $layer.find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
		if (!$focusable.length) { return; }
		var first = $focusable[0];
		var last = $focusable[$focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	function loadDetails(ui, item) {
		var $content = ui.$modalContent;
		$content.html('<p class="ppbb-loading">' + (ppbbSettings.i18n.loading || 'Loading details…') + '</p>');
		openLayer(ui.$modal);

		function render(data) {
			var $detail = $('<div class="ppbb-detail"></div>');
			$detail.append($('<img class="ppbb-detail-image">').attr({ src: data.image || item.largeImage || item.image, alt: data.name || item.name }));
			var $copy = $('<div class="ppbb-detail-copy"></div>');
			$copy.append($('<h2 id="ppbb-detail-title"></h2>').text(data.name || item.name));
			$copy.append($('<div class="ppbb-detail-price"></div>').html(data.priceHtml || item.priceHtml));
			if (data.description) { $copy.append($('<div class="ppbb-detail-description"></div>').html(data.description)); }
			if (data.attributes && data.attributes.length) {
				var $attrs = $('<dl class="ppbb-detail-attributes"></dl>');
				data.attributes.forEach(function (attr) {
					$attrs.append($('<dt></dt>').text(attr.name));
					$attrs.append($('<dd></dd>').text((attr.values || []).join(', ')));
				});
				$copy.append($attrs);
			}
			var $qty = $('<div class="ppbb-detail-qty"><span>Quantity</span><div class="ppbb-qty"><button type="button" class="ppbb-detail-minus ppbb-qty-button">−</button><output class="ppbb-detail-qty-value ppbb-qty-value"></output><button type="button" class="ppbb-detail-plus ppbb-qty-button">+</button></div></div>');
			$qty.find('.ppbb-detail-minus').attr('aria-label', 'Reduce ' + (data.name || item.name) + ' quantity');
			$qty.find('.ppbb-detail-plus').attr('aria-label', 'Increase ' + (data.name || item.name) + ' quantity');
			$qty.attr('data-product-id', item.id).find('.ppbb-detail-qty-value').text(getQty(item));
			$copy.append($qty);
			$detail.append($copy);
			$content.empty().append($detail);
		}

		if (detailCache[item.id]) { render(detailCache[item.id]); return; }
		$.ajax({ url: ppbbSettings.restUrl + item.id, method: 'GET', beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ppbbSettings.nonce); } })
			.done(function (data) { detailCache[item.id] = data; render(data); })
			.fail(function () { $content.html('<p>' + (ppbbSettings.i18n.errorDetails || 'Product details could not be loaded.') + '</p>'); });
	}

	function initBuilder($form) {
		var productId = parseInt($form.data('product_id') || $form.data('container_id') || ppbbSettings.productId, 10);
		var $mount = getMount(productId);
		if (!$mount.length || $mount.data('ppbbInitialized')) { return; }

		var apiObj = typeof $form.wc_get_mnm_script === 'function' ? $form.wc_get_mnm_script() : false;
		if (!apiObj || !apiObj.api || typeof apiObj.api.get_container_config !== 'function') { return; }

		var items = [];
		$form.find('.mnm_item').each(function () {
			var item = productDataFromNative($(this));
			if (item.id && item.$qty.length) { items.push(item); }
		});
		if (!items.length) { return; }
		var purchaseNoteTemplate = $mount.closest('.ppbb-unit').find('template.ppbb-purchase-note-template').first()[0];

		var options = {
			desktopColumns: parseInt($mount.attr('data-ppbb-desktop-columns'), 10) || 3,
			tabletColumns: parseInt($mount.attr('data-ppbb-tablet-columns'), 10) || 2,
			mobileColumns: parseInt($mount.attr('data-ppbb-mobile-columns'), 10) || 2,
			slotColumns: parseInt($mount.attr('data-ppbb-slot-columns'), 10) || 0,
			imageStyle: $mount.attr('data-ppbb-image-style') || 'landscape-4-3',
			mobileBarStyle: $mount.attr('data-ppbb-mobile-bar-style') || 'prominent',
			titleSize: $mount.attr('data-ppbb-title-size') || '',
			tileGap: $mount.attr('data-ppbb-tile-gap') || '',
			highContrastColor: $mount.attr('data-ppbb-high-contrast-color') || '',
			introMode: $mount.attr('data-ppbb-intro-mode') || 'default',
			introHeading: $mount.attr('data-ppbb-intro-heading') || '',
			introInstruction: $mount.attr('data-ppbb-intro-instruction') || '',
			showFilters: $mount.attr('data-ppbb-filters') !== '0',
			showDetails: $mount.attr('data-ppbb-details') !== '0',
			soldIndividually: $mount.attr('data-ppbb-sold-individually') === '1',
			purchaseNoteHtml: purchaseNoteTemplate ? purchaseNoteTemplate.innerHTML : ''
		};

		var ui = buildBaseUI($mount, items, apiObj.api, options);
		// ResizeObserver tracks changes to the actual box contents (selected items,
		// purchase options and custom copy). The CSS variable adjusts the sticky
		// top only; it never creates a scroll container or intercepts wheel events.
		var stickyElement = ui.$sidebarSticky[0];
		function measureStickyHeight() {
			if (!stickyElement) { return; }
			stickyElement.style.setProperty('--ppbb-sidebar-height', Math.ceil(stickyElement.getBoundingClientRect().height) + 'px');
		}
		if (stickyElement && typeof window.ResizeObserver === 'function') {
			var stickyObserver = new window.ResizeObserver(measureStickyHeight);
			stickyObserver.observe(stickyElement);
		}
		$(window).on('resize.ppbb-sticky-' + productId, measureStickyHeight);
		measureStickyHeight();
		var $interactionRoot = ui.$mount.add(ui.$drawer).add(ui.$modal);
		$mount.data('ppbbInitialized', true).addClass('is-initialized');

		function renderAll() {
			var count = apiObj.api.get_container_size();
			var max = apiObj.api.get_max_container_size();
			var total = apiObj.api.get_container_price('price');
			var valid = apiObj.api.get_validation_status() === 'pass';

			if (options.introMode === 'hidden') {
				ui.$mount.find('.ppbb-catalog-head').hide();
			} else {
				ui.$mount.find('.ppbb-catalog-head').show();
				var headingText = options.introMode === 'custom' && options.introHeading ? options.introHeading : (ppbbSettings.i18n.chooseProteins || 'Choose Your Proteins');
				var defaultInstruction = max !== false ? ('Choose ' + max + ' item' + (max === 1 ? '' : 's') + ' for your box.') : 'Build your box.';
				var instructionText = options.introMode === 'custom' && options.introInstruction ? options.introInstruction : defaultInstruction;
				instructionText = String(instructionText)
					.replace(/\{max\}/g, max !== false ? max : '')
					.replace(/\{min\}/g, apiObj.api.get_min_container_size() !== false ? apiObj.api.get_min_container_size() : '')
					.replace(/\{count\}/g, count);
				ui.$mount.find('.ppbb-heading').text(headingText);
				ui.$mount.find('.ppbb-instruction').text(instructionText);
			}

			items.forEach(function (item) {
				var qty = getQty(item);
				var $card = ui.$grid.find('.ppbb-card[data-product-id="' + item.id + '"]');
				$card.toggleClass('is-selected', qty > 0);
				$card.find('.ppbb-qty-value').text(qty);
				$card.find('.ppbb-minus').prop('disabled', qty <= item.min);
				$card.find('.ppbb-plus').prop('disabled', !item.inStock || (item.max !== null && qty >= item.max) || (max !== false && count >= max));
			});

			ui.$summary.empty().append(summaryMarkup(items, apiObj.api, $form, false, options));
			if (typeof window.ResizeObserver !== 'function') { measureStickyHeight(); }
			ui.$drawerContent.empty().append(summaryMarkup(items, apiObj.api, $form, true, options));
			ui.$mobileBar.find('.ppbb-mobile-count').text(progressLabel(apiObj.api));
			ui.$mobileBar.find('.ppbb-mobile-total').html(formatPrice(total));
			ui.$mobileBar.find('.ppbb-mobile-action').text(ppbbSettings.i18n.viewBox || 'View Box');
			ui.$mobileBar.toggleClass('is-ready', valid);

			var $detailQty = ui.$modal.find('.ppbb-detail-qty');
			if ($detailQty.length) {
				var detailId = parseInt($detailQty.attr('data-product-id'), 10);
				var detailItem = items.filter(function (i) { return i.id === detailId; })[0];
				if (detailItem) { $detailQty.find('.ppbb-detail-qty-value').text(getQty(detailItem)); }
			}
		}

		function tryAdjust(item, delta) {
			var count = apiObj.api.get_container_size();
			var max = apiObj.api.get_max_container_size();
			var current = getQty(item);
			if (delta > 0 && max !== false && count >= max) {
				announce(ui.$mount, ppbbSettings.i18n.boxFull || 'Your box is full. Remove an item before adding another.');
				ui.$mount.addClass('ppbb-shake-summary');
				setTimeout(function () { ui.$mount.removeClass('ppbb-shake-summary'); }, 350);
				return;
			}
			setQty(item, current + delta * item.step);
		}

		$interactionRoot.on('click', '.ppbb-plus, .ppbb-line-plus, .ppbb-detail-plus', function () {
			var id = parseInt($(this).closest('[data-product-id]').attr('data-product-id'), 10);
			var item = items.filter(function (i) { return i.id === id; })[0];
			if (item) { tryAdjust(item, 1); }
		});
		$interactionRoot.on('click', '.ppbb-minus, .ppbb-line-minus, .ppbb-detail-minus', function () {
			var id = parseInt($(this).closest('[data-product-id]').attr('data-product-id'), 10);
			var item = items.filter(function (i) { return i.id === id; })[0];
			if (item) { tryAdjust(item, -1); }
		});

		ui.$mount.on('click', '.ppbb-details-link, .ppbb-card-image-button:not(.is-static)', function () {
			var id = parseInt($(this).closest('.ppbb-card').attr('data-product-id'), 10);
			var item = items.filter(function (i) { return i.id === id; })[0];
			if (item) { loadDetails(ui, item); }
		});

		ui.$mount.on('click', '.ppbb-filter', function () {
			var filter = $(this).attr('data-filter');
			ui.$mount.find('.ppbb-filter').removeClass('is-active');
			$(this).addClass('is-active');
			items.forEach(function (item) {
				ui.$grid.find('.ppbb-card[data-product-id="' + item.id + '"]').toggle(filter === 'all' || item.categories.indexOf(filter) !== -1);
			});
		});

		$interactionRoot.on('change', '.ppbb-purchase-type-radio', function () {
			if ($(this).val() === 'one-time') {
				setPurchaseValue($form, '0');
			} else {
				var subscriptionOptions = getPurchaseOptions($form).filter(function (option) { return !option.isOneTime; });
				var preferred = preferredSubscriptionValue($form, subscriptionOptions);
				if (preferred) { setPurchaseValue($form, preferred); }
			}
			setTimeout(renderAll, 60);
		});
		$interactionRoot.on('change', '.ppbb-frequency-select', function () {
			setPurchaseValue($form, String($(this).val()));
			setTimeout(renderAll, 80);
		});
		$interactionRoot.on('click', '.ppbb-clear', function () { $form.trigger('wc-mnm-container-reset'); });
		$interactionRoot.on('click', '.ppbb-add', function () {
			if (apiObj.api.get_validation_status() !== 'pass') {
				if (window.matchMedia('(max-width: 700px)').matches) { closeLayer(ui.$drawer); }
				return;
			}
			var $native = $form.find('.single_add_to_cart_button').last();
			if ($native.length && !$native.prop('disabled')) { $native[0].click(); }
		});

		$interactionRoot.on('click', '.ppbb-box-minus, .ppbb-box-plus', function () {
			var $nativeQty = $form.find('.mnm_button_wrap > .quantity input.qty, .add_to_cart_button_wrap > .quantity input.qty').first();
			if (!$nativeQty.length) { return; }
			var current = parseFloat($nativeQty.val()) || 1;
			var step = parseFloat($nativeQty.attr('step')) || 1;
			var min = parseFloat($nativeQty.attr('min')) || 1;
			var max = parseFloat($nativeQty.attr('max'));
			var next = current + ($(this).hasClass('ppbb-box-plus') ? step : -step);
			next = Math.max(min, next);
			if (!isNaN(max)) { next = Math.min(max, next); }
			$nativeQty.val(next).trigger('change');
			renderAll();
		});

		ui.$mobileBar.on('click', function () { $(this).attr('aria-expanded', 'true'); openLayer(ui.$drawer); });
		$interactionRoot.on('click', '.ppbb-dialog-close, .ppbb-drawer-backdrop, .ppbb-modal-backdrop', function () {
			if ($(this).closest('.ppbb-drawer').length) {
				ui.$mobileBar.attr('aria-expanded', 'false');
				closeLayer(ui.$drawer);
			} else if ($(this).closest('.ppbb-modal').length) {
				closeLayer(ui.$modal);
			}
		});
		$interactionRoot.on('keydown', '.ppbb-drawer.is-open, .ppbb-modal.is-open', function (e) { trapFocus(e, $(this)); });
		$(document).on('keydown.ppbb-' + productId, function (e) {
			if (e.key === 'Escape') {
				if (ui.$modal.hasClass('is-open')) { closeLayer(ui.$modal); }
				else if (ui.$drawer.hasClass('is-open')) { ui.$mobileBar.attr('aria-expanded', 'false'); closeLayer(ui.$drawer); }
			}
		});

		$form.on('wc-mnm-form-updated wc-mnm-container-quantities-updated wc-mnm-validation-status-changed wc-mnm-updated-totals', function () {
			setTimeout(renderAll, 0);
		});

		// Mix & Match's official APFS compatibility updates subscription prices after
		// the base MNM update event. Re-render only after that official calculation
		// has completed so our UI displays the current configured box price instead
		// of the product's initial min/max price range.
		$form.on('wcsatt-updated-mnm-subscription-totals', function () {
			setTimeout(renderAll, 0);
		});
		$form.on('wcsatt-updated-mnm-price', function () {
			setTimeout(renderAll, 0);
		});
		$form.on('change', '.wcsatt-options-product input, .wcsatt-options-prompt-action-input, .wcsatt-options-product-dropdown', function () {
			setTimeout(renderAll, 80);
		});

		// Hide native presentation only after all required state/API checks passed.
		// Keep inputs mounted/submittable, but remove them from the keyboard focus order.
		$form.addClass('ppbb-native-hidden').attr('aria-hidden', 'true');
		$form.find('a, button, input, select, textarea, [tabindex]').attr('tabindex', '-1');
		$('body').addClass('ppbb-active ppbb-overlay-mounted');
		renderAll();
	}

	function attemptInit($form, tries) {
		tries = tries || 0;
		if (!$form.length) { return; }
		var script = typeof $form.wc_get_mnm_script === 'function' ? $form.wc_get_mnm_script() : false;
		if (script && script.api) { initBuilder($form); return; }
		if (tries < 40) { setTimeout(function () { attemptInit($form, tries + 1); }, 125); }
	}

	$(function () {
		$('form.mnm_form').each(function () {
			var $form = $(this);
			$form.on('wc-mnm-initialized', function () { initBuilder($form); });
			attemptInit($form, 0);
		});
	});
})(jQuery);
