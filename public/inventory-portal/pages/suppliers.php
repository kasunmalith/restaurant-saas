<?php
$restaurantId = (int)$context['restaurant_id'];
$query = $db->prepare('SELECT * FROM inventory_suppliers WHERE restaurant_id = ? ORDER BY is_active DESC, name');
$query->execute([$restaurantId]);
$suppliers = $query->fetchAll();
?>
<div class="page-heading"><div><p class="eyebrow">PURCHASING DIRECTORY</p><h2>Suppliers</h2><p>Keep vendor contact details separate for each restaurant.</p></div></div>
<section class="panel-card form-panel">
    <div class="panel-title"><div><p class="eyebrow">NEW VENDOR</p><h3>Add supplier</h3></div><span class="metric-icon">♧</span></div>
    <form method="post" action="<?= inv_e(inv_route('suppliers')) ?>" class="form-grid">
        <input type="hidden" name="action" value="create-supplier"><input type="hidden" name="return_page" value="suppliers"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>">
        <label>Supplier name<input name="name" maxlength="150" required></label><label>Contact person<input name="contact_person" maxlength="120"></label>
        <label>Phone<input name="phone" maxlength="40" type="tel"></label><label>Email<input name="email" maxlength="190" type="email"></label>
        <label>Address<input name="address" maxlength="700"></label><label>Notes<input name="notes" maxlength="1000"></label>
        <button class="button button-primary" type="submit">Add Supplier</button>
    </form>
</section>
<section class="panel-card data-panel">
    <div class="panel-title panel-title-wrap"><div><p class="eyebrow">TENANT DIRECTORY</p><h3>Suppliers</h3></div><label class="search-field">Search<input type="search" placeholder="Name, contact, phone" data-table-search="suppliers-table"></label></div>
    <?php if (!$suppliers): ?><p class="empty-state">No suppliers have been added yet.</p><?php else: ?><div class="table-scroll"><table class="data-table" id="suppliers-table"><thead><tr><th>Supplier details</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($suppliers as $supplier): ?><tr data-search-row="<?= inv_e(mb_strtolower($supplier['name'] . ' ' . ($supplier['contact_person'] ?? '') . ' ' . ($supplier['phone'] ?? '') . ' ' . ($supplier['email'] ?? ''))) ?>">
        <td><form method="post" action="<?= inv_e(inv_route('suppliers')) ?>" class="supplier-edit-row"><input type="hidden" name="action" value="update-supplier"><input type="hidden" name="return_page" value="suppliers"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>">
            <label>Name<input name="name" value="<?= inv_e($supplier['name']) ?>" maxlength="150" required></label><label>Contact person<input name="contact_person" value="<?= inv_e($supplier['contact_person'] ?? '') ?>" maxlength="120"></label><label>Phone<input name="phone" value="<?= inv_e($supplier['phone'] ?? '') ?>" maxlength="40"></label><label>Email<input name="email" type="email" value="<?= inv_e($supplier['email'] ?? '') ?>" maxlength="190"></label><label>Address<input name="address" value="<?= inv_e($supplier['address'] ?? '') ?>" maxlength="700"></label><label>Notes<input name="notes" value="<?= inv_e($supplier['notes'] ?? '') ?>" maxlength="1000"></label><button class="button button-small" type="submit">Save</button>
        </form></td>
        <td><span class="status-pill<?= $supplier['is_active'] ? '' : ' status-off' ?>"><?= $supplier['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><form method="post" action="<?= inv_e(inv_route('suppliers')) ?>"><input type="hidden" name="action" value="toggle-supplier"><input type="hidden" name="return_page" value="suppliers"><input type="hidden" name="csrf" value="<?= inv_e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>"><input type="hidden" name="active" value="<?= $supplier['is_active'] ? '0' : '1' ?>"><button class="button button-small button-quiet" type="submit"><?= $supplier['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
    </tr><tr><td colspan="3" class="muted-cell"><?= inv_e($supplier['address'] ?: 'No address') ?><?= $supplier['notes'] ? ' · ' . inv_e($supplier['notes']) : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

