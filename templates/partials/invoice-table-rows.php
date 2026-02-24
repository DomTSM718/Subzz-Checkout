<?php
/**
 * Invoice table rows partial — rendered into <tbody> by initial load and AJAX pagination.
 *
 * Expects $invoices array with 'invoices' key containing PortalInvoiceItem objects.
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;

if (!$invoices || empty($invoices['invoices'])) :
    ?>
    <tr>
        <td colspan="5" class="portal-table-empty">No invoices found.</td>
    </tr>
    <?php
    return;
endif;

foreach ($invoices['invoices'] as $invoice) :
    $status_class = $invoice['status'] === 'paid' ? 'portal-status-active' : 'portal-status-suspended';
    ?>
    <tr>
        <td><?php echo esc_html($invoice['invoiceNumber']); ?></td>
        <td><?php echo date('j M Y', strtotime($invoice['invoiceDate'])); ?></td>
        <td><?php echo esc_html($invoice['currency']); ?> <?php echo number_format($invoice['total'], 2); ?></td>
        <td><span class="portal-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($invoice['status'])); ?></span></td>
        <td>
            <?php if ($invoice['pdfAvailable']) : ?>
                <button class="portal-btn portal-btn-sm portal-download-invoice" data-invoice-id="<?php echo esc_attr($invoice['invoiceId']); ?>">
                    PDF
                </button>
            <?php else : ?>
                <span class="portal-text-muted">N/A</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
