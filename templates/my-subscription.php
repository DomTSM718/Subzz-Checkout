<?php
/**
 * My Subscription — WooCommerce My Account tab template
 *
 * Receives from Subzz_Customer_Portal::render_content():
 *   $subscription — array|false (subscription overview data)
 *   $invoices     — array|false (paginated invoice list)
 *   $contract     — array|false (contract download data)
 *   $active_tab   — string (overview|invoices|payment)
 *   $email        — string (current user email)
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;
?>

<div class="subzz-portal">

    <?php if (!$subscription) : ?>
        <div class="portal-empty-state">
            <h3>No Active Subscription</h3>
            <p>You don't have an active subscription yet. Browse our products to get started.</p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="portal-btn portal-btn-primary">Browse Products</a>
        </div>
    <?php else : ?>

        <!-- Tab Navigation -->
        <div class="portal-tabs">
            <button class="portal-tab <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" data-tab="overview">Overview</button>
            <button class="portal-tab <?php echo $active_tab === 'invoices' ? 'active' : ''; ?>" data-tab="invoices">Invoices</button>
            <button class="portal-tab <?php echo $active_tab === 'payment' ? 'active' : ''; ?>" data-tab="payment">Payment</button>
        </div>

        <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
        <div class="portal-tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="tab-overview">

            <?php if ($subscription['isSuspended']) : ?>
                <div class="portal-alert portal-alert-warning">
                    <strong>Subscription Suspended</strong>
                    <p><?php echo esc_html($subscription['suspensionReason'] ?? 'Your subscription has been suspended due to a payment issue.'); ?></p>
                    <p>Please update your payment method to reactivate.</p>
                </div>
            <?php endif; ?>

            <!-- Status Badge -->
            <div class="portal-status-row">
                <span class="portal-status-badge portal-status-<?php echo esc_attr($subscription['status']); ?>">
                    <?php echo esc_html(ucfirst($subscription['status'])); ?>
                </span>
                <span class="portal-customer-name"><?php echo esc_html($subscription['customerName']); ?></span>
            </div>

            <!-- Detail Card -->
            <div class="portal-detail-card">
                <div class="portal-detail-grid">
                    <div class="portal-detail-item">
                        <span class="portal-detail-label">Monthly Amount</span>
                        <span class="portal-detail-value"><?php echo esc_html($subscription['currency']); ?> <?php echo number_format($subscription['monthlyAmount'], 2); ?></span>
                    </div>
                    <div class="portal-detail-item">
                        <span class="portal-detail-label">Next Billing Date</span>
                        <span class="portal-detail-value"><?php echo date('j M Y', strtotime($subscription['nextBillingDate'])); ?></span>
                    </div>
                    <div class="portal-detail-item">
                        <span class="portal-detail-label">Billing Day</span>
                        <span class="portal-detail-value"><?php echo esc_html($subscription['billingDayOfMonth'] ? ordinal($subscription['billingDayOfMonth']) . ' of each month' : 'Not set'); ?></span>
                    </div>
                    <div class="portal-detail-item">
                        <span class="portal-detail-label">Term Length</span>
                        <span class="portal-detail-value"><?php echo esc_html($subscription['termLength']); ?> months</span>
                    </div>
                </div>

                <?php if (!empty($subscription['initialPaymentAmount'])) : ?>
                    <div class="portal-initial-payment-info">
                        <span class="portal-detail-label">Initial Payment</span>
                        <span class="portal-detail-value">
                            <?php echo esc_html($subscription['currency']); ?> <?php echo number_format($subscription['initialPaymentAmount'], 2); ?>
                            (monthly reduced from <?php echo esc_html($subscription['currency']); ?> <?php echo number_format($subscription['standardMonthlyAmount'], 2); ?>
                            to <?php echo esc_html($subscription['currency']); ?> <?php echo number_format($subscription['reducedMonthlyAmount'], 2); ?>)
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Term Progress -->
            <div class="portal-progress-section">
                <div class="portal-progress-header">
                    <span>Term Progress</span>
                    <span><?php echo esc_html($subscription['paymentsCompleted']); ?> of <?php echo esc_html($subscription['termLength']); ?> payments</span>
                </div>
                <div class="portal-progress-bar">
                    <div class="portal-progress-fill" style="width: <?php echo esc_attr(min(100, $subscription['termProgressPercent'])); ?>%"></div>
                </div>
                <div class="portal-progress-footer">
                    <span><?php echo number_format($subscription['termProgressPercent'], 1); ?>% complete</span>
                    <span><?php echo esc_html($subscription['paymentsRemaining']); ?> payments remaining</span>
                </div>
            </div>

            <!-- Contract Download -->
            <?php if ($contract) : ?>
                <div class="portal-contract-section">
                    <a href="<?php echo esc_url($contract['downloadUrl']); ?>" target="_blank" rel="noopener" class="portal-btn portal-btn-secondary">
                        Download Contract PDF
                    </a>
                </div>
            <?php endif; ?>

        </div>

        <!-- ═══════════════ INVOICES TAB ═══════════════ -->
        <div class="portal-tab-content <?php echo $active_tab === 'invoices' ? 'active' : ''; ?>" id="tab-invoices">

            <?php if (!$invoices || empty($invoices['invoices'])) : ?>
                <div class="portal-empty-state">
                    <h3>No Invoices Yet</h3>
                    <p>Your invoices will appear here after your first payment.</p>
                </div>
            <?php else : ?>
                <div class="portal-invoice-table-wrapper">
                    <table class="portal-invoice-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody id="invoice-table-body">
                            <?php
                            // Render initial invoice rows
                            include plugin_dir_path(dirname(__FILE__)) . 'templates/partials/invoice-table-rows.php';
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($invoices['totalPages'] > 1) : ?>
                    <div class="portal-pagination" data-current-page="<?php echo esc_attr($invoices['page']); ?>" data-total-pages="<?php echo esc_attr($invoices['totalPages']); ?>">
                        <button class="portal-btn portal-btn-sm portal-pagination-prev" <?php echo $invoices['page'] <= 1 ? 'disabled' : ''; ?>>Previous</button>
                        <span class="portal-pagination-info">Page <?php echo esc_html($invoices['page']); ?> of <?php echo esc_html($invoices['totalPages']); ?></span>
                        <button class="portal-btn portal-btn-sm portal-pagination-next" <?php echo $invoices['page'] >= $invoices['totalPages'] ? 'disabled' : ''; ?>>Next</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>

        <!-- ═══════════════ PAYMENT TAB ═══════════════ -->
        <div class="portal-tab-content <?php echo $active_tab === 'payment' ? 'active' : ''; ?>" id="tab-payment">

            <?php if ($subscription['isSuspended']) : ?>
                <div class="portal-alert portal-alert-warning">
                    <strong>Subscription Suspended</strong>
                    <p>Updating your payment method will reactivate your subscription and resume billing.</p>
                </div>
            <?php endif; ?>

            <!-- Current Card -->
            <div class="portal-card-display">
                <div class="portal-card-icon">
                    <?php echo esc_html($subscription['cardBrand'] ?? 'Card'); ?>
                </div>
                <div class="portal-card-details">
                    <span class="portal-card-number">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; <?php echo esc_html($subscription['cardLast4'] ?? '----'); ?></span>
                    <span class="portal-card-brand"><?php echo esc_html($subscription['cardBrand'] ?? 'Unknown'); ?></span>
                </div>
            </div>

            <!-- Update Button -->
            <div class="portal-payment-actions">
                <button id="portal-update-payment" class="portal-btn portal-btn-primary">
                    Update Payment Method
                </button>
                <p class="portal-help-text">
                    You'll be redirected to a secure page to update your card details.
                </p>
            </div>

        </div>

    <?php endif; ?>
</div>

<?php
/**
 * Helper: ordinal suffix for day numbers (1st, 2nd, 3rd, etc.)
 */
function ordinal($number) {
    $ends = array('th','st','nd','rd','th','th','th','th','th','th');
    if (($number % 100) >= 11 && ($number % 100) <= 13) {
        return $number . 'th';
    }
    return $number . $ends[$number % 10];
}
?>
