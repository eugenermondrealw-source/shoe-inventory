<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';

$user = require_role('admin');          // staff are redirected back to the dashboard

const STANDARD_SIZES = [7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0, 10.5, 11.0];

$errors      = [];
$old         = ['name' => '', 'brand' => '', 'category' => '', 'price' => '', 'image_url' => ''];
$oldSizes    = [];     // list of ['size' => string, 'qty' => string]
$customSizes = [];     // extra (non-standard) rows to re-display after an error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Security token invalid. Please reload the page and try again.';
    }

    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $after = ($_POST['after'] ?? 'stay') === 'inventory' ? 'inventory' : 'stay';

    /* ----- Validate shoe details ----- */
    if ($old['name'] === ''     || mb_strlen($old['name']) > 150)     $errors[] = 'Name is required (max 150 characters).';
    if ($old['brand'] === ''    || mb_strlen($old['brand']) > 100)    $errors[] = 'Brand is required (max 100 characters).';
    if ($old['category'] === '' || mb_strlen($old['category']) > 100) $errors[] = 'Category is required (max 100 characters).';

    $price = filter_var($old['price'], FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0 || $price > 9999999) $errors[] = 'Price must be a valid number of 0 or more.';

    $imageUrl = null;
    if ($old['image_url'] !== '') {
        if (!filter_var($old['image_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $old['image_url'])) {
            $errors[] = 'Image URL must be a valid http(s) link.';
        } else {
            $imageUrl = $old['image_url'];
        }
    }

    /* ----- Validate sizes ----- */
    $sizeRows    = [];   // "8.5" => qty
    $postedSizes = $_POST['sizes'] ?? [];
    if (is_array($postedSizes)) {
        foreach ($postedSizes as $row) {
            $rawSize = trim((string) ($row['size'] ?? ''));
            $rawQty  = trim((string) ($row['qty'] ?? ''));
            $oldSizes[] = ['size' => $rawSize, 'qty' => $rawQty];

            if ($rawSize === '' && $rawQty === '') continue;   // empty custom row

            $size = filter_var($rawSize, FILTER_VALIDATE_FLOAT);
            if ($size === false || $size < 1 || $size > 20 || fmod($size * 2, 1) !== 0.0) {
                $errors[] = 'Invalid size "' . $rawSize . '" (use half-size steps, e.g. 8.5).';
                continue;
            }
            $qty = $rawQty === '' ? 0 : filter_var($rawQty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
            if ($qty === false) {
                $errors[] = 'Quantity for US ' . number_format($size, 1) . ' must be a whole number of 0 or more.';
                continue;
            }
            $key = number_format($size, 1, '.', '');
            if (isset($sizeRows[$key])) {
                $errors[] = "Size US {$key} was entered more than once.";
                continue;
            }
            $sizeRows[$key] = $qty;
        }
    }
    if (!$sizeRows && !$errors) $errors[] = 'Enter stock for at least one size.';

    /* ----- Duplicate check (same name + brand) ----- */
    if (!$errors) {
        $dup = $pdo->prepare('SELECT 1 FROM shoes WHERE LOWER(name) = LOWER(:n) AND LOWER(brand) = LOWER(:b)');
        $dup->execute([':n' => $old['name'], ':b' => $old['brand']]);
        if ($dup->fetchColumn()) {
            $errors[] = '"' . $old['name'] . '" by ' . $old['brand'] . ' already exists. Use "Edit stock" on the inventory page to change its quantities.';
        }
    }

    /* ----- Insert: shoes first, then shoe_sizes (one transaction) ----- */
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $insShoe = $pdo->prepare(
                'INSERT INTO shoes (name, brand, category, price, image_url)
                 VALUES (:name, :brand, :category, :price, :image_url)'
            );
            $insShoe->execute([
                ':name' => $old['name'], ':brand' => $old['brand'], ':category' => $old['category'],
                ':price' => $price, ':image_url' => $imageUrl,
            ]);
            $shoeId = (int) $pdo->lastInsertId();

            $insSize = $pdo->prepare(
                'INSERT INTO shoe_sizes (shoe_id, size_number, quantity) VALUES (:shoe_id, :size, :qty)'
            );
            foreach ($sizeRows as $size => $qty) {
                $insSize->execute([':shoe_id' => $shoeId, ':size' => $size, ':qty' => $qty]);
            }

            $pdo->commit();
            flash('success', 'Added "' . $old['name'] . '" with ' . count($sizeRows) . ' sizes (' . array_sum($sizeRows) . ' pairs).');
            redirect($after === 'inventory' ? 'admin.php' : 'add-shoe.php');   // Post/Redirect/Get
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Database error: the shoe was not saved. Please try again.';
        }
    }

    foreach ($oldSizes as $row) {
        if (!in_array((float) $row['size'], STANDARD_SIZES, true)) $customSizes[] = $row;
    }
}

$qtyBySize = [];
foreach ($oldSizes as $row) {
    $qtyBySize[number_format((float) $row['size'], 1, '.', '')] = $row['qty'];
}

$cats = array_unique(array_merge(
    ['Running', 'Casual', 'Formal', 'Basketball', 'Sandals', 'Boots'],
    $pdo->query('SELECT DISTINCT category FROM shoes ORDER BY category')->fetchAll(PDO::FETCH_COLUMN)
));

$token = csrf_token();
$idx   = 0;

render_header('Add New Shoe', 'add');
?>
<main id="main" class="container pb-5" style="max-width: 900px;">
    <?php render_flash(); ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Add New Shoe</h1>
        <a href="admin.php" class="btn btn-outline-secondary btn-sm">View inventory</a>
    </div>

    <form method="post" action="add-shoe.php" id="shoe-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="after" id="after" value="stay">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Shoe details</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="150" required autofocus value="<?= e($old['name']) ?>">
                    <div class="invalid-feedback">Please enter the shoe name.</div>
                </div>
                <div class="col-md-6">
                    <label for="brand" class="form-label">Brand</label>
                    <input type="text" class="form-control" id="brand" name="brand" maxlength="100" required value="<?= e($old['brand']) ?>">
                    <div class="invalid-feedback">Please enter the brand.</div>
                </div>
                <div class="col-md-6">
                    <label for="category" class="form-label">Category</label>
                    <input type="text" class="form-control" id="category" name="category" maxlength="100" list="cats"
                           placeholder="Pick one or type a new category" required value="<?= e($old['category']) ?>">
                    <datalist id="cats">
                        <?php foreach ($cats as $c): ?><option value="<?= e((string) $c) ?>"><?php endforeach; ?>
                    </datalist>
                    <div class="invalid-feedback">Please enter a category.</div>
                </div>
                <div class="col-md-6">
                    <label for="price" class="form-label">Price</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text">&#8369;</span>
                        <input type="number" class="form-control" id="price" name="price" min="0" step="0.01" inputmode="decimal" required value="<?= e($old['price']) ?>">
                        <div class="invalid-feedback">Enter a price of 0 or more.</div>
                    </div>
                </div>
                <div class="col-12">
                    <label for="image_url" class="form-label">Image URL <span class="text-muted">(optional)</span></label>
                    <div class="d-flex gap-3 align-items-start">
                        <div class="flex-grow-1">
                            <input type="url" class="form-control" id="image_url" name="image_url" placeholder="https://..." value="<?= e($old['image_url']) ?>">
                            <div class="invalid-feedback">Enter a valid link starting with http:// or https://</div>
                        </div>
                        <img id="img-preview" class="img-thumbnail d-none" alt="Image preview" style="width:64px;height:64px;object-fit:cover;">
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Starting stock by US size</span>
                <span class="badge text-bg-light border fs-6 fw-normal" id="total-badge" aria-live="polite">0 pairs in 0 sizes</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 bg-light rounded">
                    <label for="fill-qty" class="small mb-0">Quick fill:</label>
                    <input type="number" id="fill-qty" class="form-control form-control-sm" style="width:80px" min="0" step="1" value="5" aria-label="Quantity to apply to every size">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fill-apply">Set every size to this</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fill-clear">Clear all to 0</button>
                </div>

                <div class="row g-3" id="size-container">
                    <?php foreach (STANDARD_SIZES as $sz): $key = number_format($sz, 1, '.', ''); ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <label class="form-label" for="qty-<?= $idx ?>">US <?= $key ?></label>
                            <input type="hidden" name="sizes[<?= $idx ?>][size]" value="<?= $key ?>">
                            <input type="number" class="form-control qty-input" id="qty-<?= $idx ?>" name="sizes[<?= $idx ?>][qty]"
                                   min="0" step="1" inputmode="numeric" value="<?= e($qtyBySize[$key] ?? '0') ?>">
                        </div>
                        <?php $idx++; ?>
                    <?php endforeach; ?>

                    <?php foreach ($customSizes as $row): ?>
                        <div class="col-12 col-sm-6 col-lg-4 custom-size">
                            <div class="input-group">
                                <span class="input-group-text">US</span>
                                <input type="number" class="form-control size-input" name="sizes[<?= $idx ?>][size]" min="1" max="20" step="0.5" value="<?= e($row['size']) ?>" aria-label="Custom size">
                                <input type="number" class="form-control qty-input" name="sizes[<?= $idx ?>][qty]" min="0" step="1" value="<?= e($row['qty']) ?>" aria-label="Quantity">
                                <button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>
                            </div>
                        </div>
                        <?php $idx++; ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="add-size">+ Add another size</button>
                <div class="form-text">Sizes left at 0 are saved too, so they show as out of stock on the dashboard.</div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success px-4" data-after="stay">Save shoe
                <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
            <button type="submit" class="btn btn-outline-success" data-after="inventory">Save &amp; view inventory</button>
            <a href="admin.php" class="btn btn-light border">Cancel</a>
        </div>
    </form>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    const form = document.getElementById('shoe-form');
    const container = document.getElementById('size-container');
    const totalBadge = document.getElementById('total-badge');
    let nextIndex = <?= (int) $idx ?>;
    let dirty = false;

    /* Live total */
    function updateTotal() {
        let pairs = 0, sizes = 0;
        form.querySelectorAll('.qty-input').forEach(function (i) {
            const v = parseInt(i.value, 10);
            if (v > 0) { pairs += v; sizes++; }
        });
        totalBadge.textContent = pairs + ' pair' + (pairs === 1 ? '' : 's') + ' in ' + sizes + ' size' + (sizes === 1 ? '' : 's');
    }

    /* Quick fill */
    document.getElementById('fill-apply').addEventListener('click', function () {
        const n = Math.max(0, parseInt(document.getElementById('fill-qty').value, 10) || 0);
        form.querySelectorAll('.qty-input').forEach(function (i) { i.value = n; });
        dirty = true; updateTotal();
    });
    document.getElementById('fill-clear').addEventListener('click', function () {
        form.querySelectorAll('.qty-input').forEach(function (i) { i.value = 0; });
        dirty = true; updateTotal();
    });

    /* Custom size rows */
    document.getElementById('add-size').addEventListener('click', function () {
        const col = document.createElement('div');
        col.className = 'col-12 col-sm-6 col-lg-4 custom-size';
        col.innerHTML =
            '<div class="input-group has-validation">' +
            '<span class="input-group-text">US</span>' +
            '<input type="number" class="form-control size-input" name="sizes[' + nextIndex + '][size]" min="1" max="20" step="0.5" placeholder="Size" required aria-label="Custom size">' +
            '<input type="number" class="form-control qty-input" name="sizes[' + nextIndex + '][qty]" min="0" step="1" value="0" aria-label="Quantity">' +
            '<button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>' +
            '<div class="invalid-feedback">Enter a size like 6.5 or 12.</div></div>';
        container.appendChild(col);
        nextIndex++;
        col.querySelector('.size-input').focus();
        dirty = true;
    });
    container.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.remove-size');
        if (btn) { btn.closest('.custom-size').remove(); dirty = true; updateTotal(); }
    });

    /* Image preview */
    const img = document.getElementById('img-preview');
    const urlInput = document.getElementById('image_url');
    function preview() {
        const v = urlInput.value.trim();
        if (/^https?:\/\//i.test(v)) { img.src = v; } else { img.classList.add('d-none'); img.removeAttribute('src'); }
    }
    img.addEventListener('load',  function () { img.classList.remove('d-none'); });
    img.addEventListener('error', function () { img.classList.add('d-none'); });
    urlInput.addEventListener('change', preview);
    preview();

    /* Track edits, warn before leaving with unsaved data */
    form.addEventListener('input', function () { dirty = true; updateTotal(); });
    window.addEventListener('beforeunload', function (ev) {
        if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
    });

    /* Which button was pressed -> hidden field (a disabled button wouldn't be sent) */
    form.addEventListener('submit', function (ev) {
        if (!form.checkValidity()) {
            ev.preventDefault();
            form.classList.add('was-validated');
            const bad = form.querySelector(':invalid');
            if (bad) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); bad.focus({ preventScroll: true }); }
            return;
        }
        const sub = ev.submitter;
        document.getElementById('after').value = sub && sub.dataset.after ? sub.dataset.after : 'stay';
        dirty = false;
        if (sub) sub.classList.add('is-loading');
        setTimeout(function () {
            form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
        }, 0);
    });

    updateTotal();
})();
</script>
</body>
</html>
