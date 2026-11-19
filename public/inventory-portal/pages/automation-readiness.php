<?php
require_once __DIR__ . '/../app/automation-service.php';
$readiness = inventory_automation_readiness($db, (int)$context['restaurant_id']);
$status = $readiness['status'];
?>
<div class="page-heading"><div><p class="eyebrow">KOT STOCK SAFETY</p><h2>Inventory Automation Readiness</h2><p>This is a read-only check. It does not initialize, schedule, or run stock deduction.</p></div></div>
<section class="panel-card">
    <h3><?= $readiness['ready'] ? 'READY' : 'NOT READY' ?></h3>
    <?php if ($readiness['issues']): ?>
        <p>Resolve these blocking issues before enabling automatic stock deduction:</p>
        <ul><?php foreach ($readiness['issues'] as $issue): ?><li><?= inv_e($issue) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p>Portal data checks passed. Verify the external worker schedule and a safe dry-run separately before activation.</p><?php endif; ?>
</section>
<section class="panel-card">
    <h3>Automation Status</h3>
    <dl>
        <dt>KOT Worker</dt><dd><?= $status['initialized'] ? 'Initialized' : 'Not Initialized' ?></dd>
        <dt>Automation</dt><dd><?= inv_e($status['schedule']) ?> — external scheduler status is not recorded by the portal</dd>
        <dt>Last Successful Run</dt><dd><?= $status['last_successful_run'] === null ? 'Not recorded' : inv_e($status['last_successful_run'].' UTC') ?></dd>
        <dt>Last Processed KOT</dt><dd><?= $status['last_processed_kot'] ? '#'.(int)$status['last_processed_kot']['kot_item_id'].' / '.inv_e($status['last_processed_kot']['event_at'].' UTC') : 'None recorded for this restaurant' ?></dd>
        <dt>Pending Review Events</dt><dd><?= (int)$status['pending_reviews'] ?></dd>
        <dt>Unresolved Recipe Mappings</dt><dd><?= (int)$status['unresolved_mappings'] ?></dd>
    </dl>
    <p class="muted">A recent successful run does not prove that an external scheduler is active. Dry-run is CLI-only and never changes stock.</p>
</section>
