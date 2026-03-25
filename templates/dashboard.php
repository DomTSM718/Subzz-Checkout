<?php
/**
 * Dashboard — WooCommerce My Account landing page template
 *
 * Receives from Subzz_Customer_Portal::render_dashboard():
 *   $subscriptions     — array (all customer subscriptions, may be empty)
 *   $featured_products — array (featured/latest product cards)
 *   $user              — WP_User object
 *
 * @since 2.1.0
 */

if (!defined('ABSPATH')) exit;
?>

<div class="subzz-dashboard">

    <!-- Welcome -->
    <div class="dashboard-welcome">
        <h2>Welcome back<?php echo $user && $user->first_name ? ', ' . esc_html($user->first_name) : ''; ?></h2>
    </div>

    <?php if (!empty($subscriptions)) : ?>

        <!-- Subscription Cards -->
        <div class="dashboard-subscriptions">
            <h3>Your Subscription<?php echo count($subscriptions) > 1 ? 's' : ''; ?></h3>

            <?php foreach ($subscriptions as $sub) : ?>
                <div class="dashboard-subscription-card">
                    <div class="dashboard-card-header">
                        <div class="dashboard-card-title">
                            <?php if (!empty($sub['productName'])) : ?>
                                <span class="dashboard-product-title"><?php echo esc_html($sub['productName']); ?></span>
                            <?php endif; ?>
                            <span class="dashboard-term-label"><?php echo esc_html($sub['termLength']); ?>-month plan</span>
                        </div>
                        <span class="portal-status-badge portal-status-<?php echo esc_attr($sub['status']); ?>">
                            <?php echo esc_html(ucfirst($sub['status'])); ?>
                        </span>
                    </div>

                    <?php if ($sub['isSuspended']) : ?>
                        <div class="portal-alert portal-alert-warning" style="margin-bottom: var(--subzz-space-4);">
                            <strong>Payment Issue</strong>
                            <p>Please <a href="<?php echo esc_url(wc_get_account_endpoint_url('my-subscription') . '?sid=' . esc_attr($sub['subscriptionId']) . '&tab=payment'); ?>">update your payment method</a> to continue.</p>
                        </div>
                    <?php endif; ?>

                    <div class="dashboard-summary-grid">
                        <div class="dashboard-summary-item">
                            <span class="dashboard-summary-label">Monthly Amount</span>
                            <span class="dashboard-summary-value"><?php echo esc_html($sub['currency']); ?> <?php echo number_format(ceil($sub['monthlyAmount']), 0); ?></span>
                        </div>
                        <div class="dashboard-summary-item">
                            <span class="dashboard-summary-label">Next Payment</span>
                            <span class="dashboard-summary-value"><?php echo date('j M Y', strtotime($sub['nextBillingDate'])); ?></span>
                        </div>
                        <div class="dashboard-summary-item">
                            <span class="dashboard-summary-label">Progress</span>
                            <span class="dashboard-summary-value"><?php echo esc_html($sub['paymentsCompleted']); ?> of <?php echo esc_html($sub['termLength']); ?> payments</span>
                        </div>
                        <div class="dashboard-summary-item">
                            <span class="dashboard-summary-label">Remaining</span>
                            <span class="dashboard-summary-value"><?php echo esc_html($sub['paymentsRemaining']); ?> payments</span>
                        </div>
                    </div>

                    <!-- Compact progress bar -->
                    <div class="dashboard-progress">
                        <div class="portal-progress-bar">
                            <div class="portal-progress-fill" style="width: <?php echo esc_attr(min(100, $sub['termProgressPercent'])); ?>%"></div>
                        </div>
                        <span class="dashboard-progress-label"><?php echo number_format($sub['termProgressPercent'], 0); ?>%</span>
                    </div>

                    <div class="dashboard-card-actions">
                        <a href="<?php echo esc_url(wc_get_account_endpoint_url('my-subscription') . '?sid=' . esc_attr($sub['subscriptionId'])); ?>" class="portal-btn portal-btn-secondary portal-btn-sm">View Details</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>

        <!-- No Subscription — CTA -->
        <div class="dashboard-no-subscription">
            <h3>Ready to get started?</h3>
            <p>Browse our range of golf equipment available on subscription. Get approved, pick your gear, and pay monthly.</p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="portal-btn portal-btn-primary">Start Shopping</a>
        </div>

    <?php endif; ?>

    <!-- Featured Products -->
    <?php if (!empty($featured_products)) : ?>
        <div class="dashboard-products-section">
            <div class="dashboard-products-header">
                <h3>Featured Products</h3>
                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="dashboard-shop-link">View All</a>
            </div>
            <div class="dashboard-products-grid">
                <?php foreach ($featured_products as $product) : ?>
                    <a href="<?php echo esc_url($product['permalink']); ?>" class="dashboard-product-card">
                        <?php if ($product['image']) : ?>
                            <div class="dashboard-product-image">
                                <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['name']); ?>" loading="lazy">
                            </div>
                        <?php else : ?>
                            <div class="dashboard-product-image dashboard-product-no-image">
                                <span>No image</span>
                            </div>
                        <?php endif; ?>
                        <div class="dashboard-product-info">
                            <span class="dashboard-product-name"><?php echo esc_html($product['name']); ?></span>
                            <?php if ($product['price']) : ?>
                                <span class="dashboard-product-price">From R <?php echo number_format(ceil($product['price']), 0); ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
