(() => {
    const sidebar = document.getElementById('portal-sidebar');
    const scrim = document.querySelector('.mobile-scrim');
    const toggle = document.querySelector('[data-menu-toggle]');
    const close = () => {
        sidebar?.classList.remove('is-open');
        scrim?.classList.remove('is-open');
        toggle?.setAttribute('aria-expanded', 'false');
    };
    toggle?.addEventListener('click', () => {
        const open = !sidebar?.classList.contains('is-open');
        sidebar?.classList.toggle('is-open', open);
        scrim?.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
    });
    scrim?.addEventListener('click', close);
    document.querySelectorAll('[data-close-menu], .nav-link').forEach((element) => element.addEventListener('click', close));
})();

document.querySelectorAll('[data-table-search]').forEach((input) => {
    const table = document.getElementById(input.dataset.tableSearch || '');
    input.addEventListener('input', () => {
        const term = input.value.trim().toLocaleLowerCase();
        table?.querySelectorAll('[data-search-row]').forEach((row) => {
            row.classList.toggle('row-hidden', !row.dataset.searchRow?.includes(term));
        });
    });
});

const unitKind = document.querySelector('[data-unit-kind]');
const baseUnitField = document.querySelector('[data-base-unit-field]');
const conversionField = document.querySelector('[data-conversion-field]');
const baseUnitSelect = baseUnitField?.querySelector('select');
const conversionInput = conversionField?.querySelector('input');
const updateUnitForm = () => {
    const isBase = unitKind?.value === '1';
    if (baseUnitField) baseUnitField.hidden = isBase;
    if (conversionField) conversionField.hidden = isBase;
    if (baseUnitSelect) baseUnitSelect.required = !isBase;
    if (conversionInput) conversionInput.required = !isBase;
};
unitKind?.addEventListener('change', updateUnitForm);
updateUnitForm();

document.addEventListener('click', (event) => {
    const add = event.target.closest('[data-add-ingredient]');
    if (add) {
        const list = document.querySelector('[data-ingredient-list]');
        const template = list?.querySelector('[data-ingredient-row]');
        if (!list || !template) return;
        const row = template.cloneNode(true);
        row.querySelectorAll('input').forEach((field) => { field.value = ''; });
        row.querySelectorAll('select').forEach((field) => { field.selectedIndex = 0; });
        list.append(row);
        return;
    }
    const remove = event.target.closest('[data-remove-ingredient]');
    if (remove) {
        const rows = document.querySelectorAll('[data-ingredient-row]');
        if (rows.length > 1) remove.closest('[data-ingredient-row]')?.remove();
    }
});

const recipeForm = document.querySelector('.recipe-form');
if (recipeForm) {
    const numberFormat = new Intl.NumberFormat('en-LK', { style: 'currency', currency: 'LKR', minimumFractionDigits: 2 });
    const refreshRecipePreview = () => {
        let total = 0;
        let complete = true;
        recipeForm.querySelectorAll('[data-ingredient-row]').forEach((row) => {
            const material = row.querySelector('[data-recipe-material]');
            const unit = row.querySelector('[data-recipe-unit]');
            const quantity = Number(row.querySelector('[name="quantity[]"]')?.value || 0);
            const materialOption = material?.selectedOptions[0];
            const unitOption = unit?.selectedOptions[0];
            if (!materialOption?.value || !unitOption?.value || quantity <= 0) return;
            if (materialOption.dataset.costKnown !== '1') complete = false;
            total += quantity * Number(unitOption.dataset.factor || 0) * Number(materialOption.dataset.averageCost || 0);
        });
        const yieldQuantity = Number(recipeForm.querySelector('[name="yield_quantity"]')?.value || 0);
        const sellingPrice = Number(recipeForm.querySelector('[data-menu-mapping]')?.selectedOptions[0]?.dataset.sellingPrice || 0);
        const costNode = recipeForm.querySelector('[data-preview-cost]');
        const profitNode = recipeForm.querySelector('[data-preview-profit]');
        if (costNode) costNode.textContent = complete && yieldQuantity > 0 ? numberFormat.format(total / yieldQuantity) + ' per yield' : 'Incomplete — cost data required';
        if (profitNode) {
            profitNode.textContent = complete && yieldQuantity > 0 && sellingPrice > 0
                ? 'Estimated gross profit: ' + numberFormat.format(sellingPrice - total / yieldQuantity) + ' per menu serving. Excludes operating expenses.'
                : 'Choose a menu mapping and enter ingredient quantities to preview current food cost.';
        }
    };
    const updateUnitsForRow = (row) => {
        const material = row.querySelector('[data-recipe-material]');
        const unit = row.querySelector('[data-recipe-unit]');
        const baseUnit = material?.selectedOptions[0]?.dataset.baseUnit;
        if (!unit || !baseUnit) return;
        const compatible = [];
        unit.querySelectorAll('option').forEach((option, index) => {
            const allowed = index === 0 || option.dataset.baseUnit === baseUnit;
            option.disabled = !allowed;
            option.hidden = !allowed;
            if (allowed && index > 0) compatible.push(option);
        });
        if (unit.selectedOptions[0]?.disabled) unit.value = compatible[0]?.value || '';
    };
    recipeForm.addEventListener('change', (event) => {
        const row = event.target.closest('[data-ingredient-row]');
        if (row && event.target.matches('[data-recipe-material]')) updateUnitsForRow(row);
        refreshRecipePreview();
    });
    recipeForm.addEventListener('input', refreshRecipePreview);
    recipeForm.querySelectorAll('[data-ingredient-row]').forEach(updateUnitsForRow);
    refreshRecipePreview();
}

const wastageForm = document.querySelector('.wastage-form');
if (wastageForm) {
    const material = wastageForm.querySelector('[data-wastage-material]');
    const unit = wastageForm.querySelector('[data-wastage-unit]');
    const updateWastageUnits = () => {
        const baseUnit = material?.selectedOptions[0]?.dataset.baseUnit;
        if (!unit || !baseUnit) return;
        const compatible = [];
        unit.querySelectorAll('option').forEach((option, index) => {
            const allowed = index === 0 || option.dataset.baseUnit === baseUnit;
            option.disabled = !allowed;
            option.hidden = !allowed;
            if (allowed && index > 0) compatible.push(option);
        });
        if (unit.selectedOptions[0]?.disabled) unit.value = compatible[0]?.value || '';
    };
    material?.addEventListener('change', updateWastageUnits);
    updateWastageUnits();
}

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm || 'Continue with this action?')) event.preventDefault();
    });
});

document.querySelectorAll('select[data-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => select.form?.requestSubmit());
});
const reportPeriod = document.querySelector('[data-report-period]');
document.querySelectorAll('.report-filter-form input[type="date"]').forEach((input) => {
    input.addEventListener('change', () => { if (reportPeriod) reportPeriod.value = 'custom'; });
});
