/**
 * Subzz Product Attribute Tiles — interaction handler.
 *
 * Handles click-to-select for non-variation attribute tiles (Hand, Flex, Loft, etc.).
 * Mirrors Marc's Duration tile behaviour: click a button → add is-active, remove from siblings,
 * update the hidden form field so the selection flows through to cart.
 *
 * Only targets attribute groups that are NOT subscription-duration (Marc's JS handles that).
 *
 * @since 1.0.0
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $wrapper = $('.subzz-attribute-tiles-wrapper');
        if (!$wrapper.length) {
            return;
        }

        // Click handler for attribute tile buttons (excluding Duration — Marc's JS owns that)
        $wrapper.on('click', '.subzz-term-btn', function (e) {
            e.preventDefault();

            var $btn  = $(this);
            var group = $btn.data('attr');
            var value = $btn.data('value');

            // Toggle active state within the same attribute group
            $wrapper
                .find('.subzz-term-btn[data-attr="' + group + '"]')
                .removeClass('is-active');
            $btn.addClass('is-active');

            // Update the corresponding hidden field
            $wrapper
                .find('input.subzz-attr-hidden[data-attr="' + group + '"]')
                .val(value);
        });
    });
})(jQuery);
