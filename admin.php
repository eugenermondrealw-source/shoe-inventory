<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';

$user     = require_login();            // admin or staff
$isAdmin  = $user['role'] === 'admin';
$lowLimit = 3;                          // 1..3 pairs = "low stock"

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('danger', 'Security token invalid. Please try again.');
        redirect('admin.php');
    }
    $action = $_POST['action'] ?? '';
    $shoeId = filter_var($_POST['shoe_id'] ?? null, FILTER_VALIDATE_INT);

    $nameStmt = $pdo->prepare('SELECT name FROM shoes WHERE id = :id');
    $nameStmt->execute([':id' => $shoeId ?: 0]);
    $shoeName = $nameStmt->fetchColumn();

    if (!$shoeId || $shoeName === false) {
        flash('danger', 'That shoe no longer exists.');
        redirect('admin.php');
    }

    if ($action === 'update_stock') {                     // admin + staff
        $qtys = $_POST['qty'] ?? [];
        if (!is_array($qtys)) {
            flash('danger', 'Invalid update request.');
            redirect('admin.php');
        }
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE shoe_sizes SET quantity = :qty WHERE id = :id AND shoe_id = :shoe_id');
            foreach ($qtys as $sizeId => $qty) {
                $sizeId = filter_var($sizeId, FILTER_VALIDATE_INT);
                $qty    = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
                if ($sizeId === false || $qty === false) {
                    throw new InvalidArgumentException('Quantities must be whole numbers of 0 or more.');
                }
                $upd->execute([':qty' => $qty, ':id' => $sizeId, ':shoe_id' => $shoeId]);
            }
            $pdo->commit();
            flash('success', 'Stock updated for "' . $shoeName . '".');
        } catch (InvalidArgumentException $ex) {
            $pdo->rollBack();
            flash('danger', $ex->getMessage());
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            flash('danger', 'Database error while updating stock.');
        }

    } elseif ($action === 'delete_shoe') {                // admin only
        if (!$isAdmin) {
            flash('danger', 'Only admins can delete shoes.');
            redirect('admin.php');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM shoe_sizes WHERE shoe_id = :id')->execute([':id' => $shoeId]);
            $pdo->prepare('DELETE FROM shoes WHERE id = :id')->execute([':id' => $shoeId]);
            $pdo->commit();
            flash('success', '"' . $shoeName . '" was deleted.');
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            flash('danger', 'Database error: the shoe was not deleted.');
        }
    }
    redirect('admin.php');
}

/* ---------- Load stock ---------- */
$rows = $pdo->query(
    'SELECT s.id AS shoe_id, s.name, s.brand, s.category, s.price, s.image_url,
            z.id AS size_id, z.size_number, z.quantity
       FROM shoes s
       LEFT JOIN shoe_sizes z ON z.shoe_id = s.id
      ORDER BY s.name ASC, z.size_number ASC'
)->fetchAll();

$shoes = [];
foreach ($rows as $r) {
    $id = (int) $r['shoe_id'];
    if (!isset($shoes[$id])) {
        $shoes[$id] = [
            'name' => $r['name'], 'brand' => $r['brand'], 'category' => $r['category'],
            'price' => (float) $r['price'], 'image_url' => $r['image_url'],
            'sizes' => [], 'total' => 0, 'out' => 0, 'low' => 0,
        ];
    }
    if ($r['size_id'] !== null) {
        $qty = (int) $r['quantity'];
        $shoes[$id]['sizes'][] = ['id' => (int) $r['size_id'], 'size' => (float) $r['size_number'], 'qty' => $qty];
        $shoes[$id]['total'] += $qty;
        if ($qty === 0)              $shoes[$id]['out']++;
        elseif ($qty <= $lowLimit)   $shoes[$id]['low']++;
    }
}

$stats = ['models' => count($shoes), 'pairs' => 0, 'out' => 0, 'low' => 0];
$categories = [];
foreach ($shoes as $s) {
    $stats['pairs'] += $s['total'];
    $stats['out']   += $s['out'];
    $stats['low']   += $s['low'];
    $categories[$s['category']] = true;
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$token = csrf_token();

$css = <<<'CSS'
<style>
    .shoe-thumb { width: 56px; height: 56px; object-fit: cover; border-radius: .5rem; background: #e9ecef; flex-shrink: 0; }
    .size-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: .4rem; min-width: 300px; }
    .size-cell { border: 1px solid #dee2e6; border-radius: .5rem; text-align: center; padding: .25rem .2rem; background: #fff; }
    .size-cell .sz { font-size: .72rem; color: #6c757d; display: block; }
    .size-cell .qt { font-weight: 700; font-size: 1.05rem; line-height: 1.2; display: block; }
    .size-cell .flag { display: block; font-size: .6rem; font-weight: 700; letter-spacing: .05em; min-height: .9rem; }
    .size-cell.out { background: #f8d7da; border-color: #dc3545; }
    .size-cell.out .sz, .size-cell.out .qt, .size-cell.out .flag { color: #b02a37; }
    .size-cell.low { background: #fff3cd; border-color: #ffc107; }
    .size-cell.low .flag { color: #8a6500; }
    .stat-card { text-align: left; width: 100%; }
    button.stat-card:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.12) !important; }
    .stat-card .num { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
    .qty-input.is-changed { border-color: #ffc107; background: #fffbea; box-shadow: 0 0 0 .15rem rgba(255,193,7,.25); }
    tbody.shoe-group > tr:first-child > td { border-top: 1px solid #dee2e6; }
</style>
CSS;

render_header('Inventory', 'inventory', true, $css);
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Current Stock</h1>
        <?php if ($isAdmin): ?>
            <a href="add-shoe.php" class="btn btn-success">+ Add New Shoe</a>
        <?php endif; ?>
    </div>

    <!-- Summary cards (the red/amber ones are shortcuts to the filter) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm stat-card h-100"><div class="card-body">
                <div class="small text-muted">Shoe models</div><div class="num"><?= $stats['models'] ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm stat-card h-100"><div class="card-body">
                <div class="small text-muted">Pairs in stock</div><div class="num"><?= number_format($stats['pairs']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm stat-card h-100 p-0 border-0 bg-white" data-quick-filter="out" aria-label="Show shoes with out-of-stock sizes">
                <span class="card-body d-block">
                    <span class="small text-muted d-block">Sizes out of stock</span>
                    <span class="num text-danger"><?= $stats['out'] ?></span>
                </span>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm stat-card h-100 p-0 border-0 bg-white" data-quick-filter="low" aria-label="Show shoes with low-stock sizes">
                <span class="card-body d-block">
                    <span class="small text-muted d-block">Low-stock sizes (1&ndash;<?= $lowLimit ?>)</span>
                    <span class="num" style="color:#b58100"><?= $stats['low'] ?></span>
                </span>
            </button>
        </div>
    </div>

    <?php if (!$shoes): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5">
            <p class="text-muted mb-3">No shoes in the inventory yet.</p>
            <?php if ($isAdmin): ?><a href="add-shoe.php" class="btn btn-success">Add your first shoe</a>
            <?php else: ?><p class="small text-muted mb-0">Ask an admin to add shoes.</p><?php endif; ?>
        </div></div>
    <?php else: ?>

    <!-- Filters -->
    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="filter-q" class="form-label small mb-1">Search <span class="text-muted">(press / to focus)</span></label>
                <input type="search" id="filter-q" class="form-control" placeholder="Name, brand or category" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="filter-cat" class="form-label small mb-1">Category</label>
                <select id="filter-cat" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="filter-status" class="form-label small mb-1">Stock</label>
                <select id="filter-status" class="form-select">
                    <option value="">All</option>
                    <option value="out">Has out-of-stock sizes</option>
                    <option value="low">Has low-stock sizes</option>
                    <option value="ok">Fully stocked</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="sort" class="form-label small mb-1">Sort by</label>
                <select id="sort" class="form-select">
                    <option value="name">Name (A-Z)</option>
                    <option value="out-desc">Most out-of-stock sizes</option>
                    <option value="stock-asc">Lowest total stock</option>
                    <option value="price-asc">Price (low to high)</option>
                    <option value="price-desc">Price (high to low)</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-grid">
                <button type="button" id="clear-filters" class="btn btn-outline-secondary">Clear filters</button>
            </div>
        </div>
        <div class="small text-muted mt-2" id="result-count" aria-live="polite"></div>
    </div></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0" id="stock-table">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Shoe</th>
                        <th scope="col">Brand</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col">Stock by US size (pairs left)</th>
                        <th scope="col" class="text-center">Total</th>
                    </tr>
                </thead>
                <tbody id="no-results" hidden>
                    <tr><td colspan="5" class="text-center text-muted py-5">No shoes match your filters.</td></tr>
                </tbody>

                <?php foreach ($shoes as $shoeId => $shoe): ?>
                <tbody class="shoe-group"
                       data-search="<?= e(mb_strtolower($shoe['name'] . ' ' . $shoe['brand'] . ' ' . $shoe['category'])) ?>"
                       data-category="<?= e($shoe['category']) ?>"
                       data-name="<?= e(mb_strtolower($shoe['name'])) ?>"
                       data-price="<?= $shoe['price'] ?>"
                       data-total="<?= $shoe['total'] ?>"
                       data-out="<?= $shoe['out'] ?>"
                       data-low="<?= $shoe['low'] ?>">
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <?php if (!empty($shoe['image_url'])): ?>
                                    <img class="shoe-thumb" src="<?= e($shoe['image_url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <div class="shoe-thumb" aria-hidden="true"></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold"><?= e($shoe['name']) ?></div>
                                    <div class="small text-muted"><?= e($shoe['category']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= e($shoe['brand']) ?></td>
                        <td class="text-end text-nowrap">&#8369;<?= number_format($shoe['price'], 2) ?></td>
                        <td>
                            <?php if ($shoe['sizes']): ?>
                                <div class="size-grid">
                                    <?php foreach ($shoe['sizes'] as $s):
                                        $cls  = $s['qty'] === 0 ? 'out' : ($s['qty'] <= $lowLimit ? 'low' : '');
                                        $flag = $s['qty'] === 0 ? 'OUT' : ($s['qty'] <= $lowLimit ? 'LOW' : '');
                                        $tip  = 'US ' . number_format($s['size'], 1) . ': ' . $s['qty'] . ' pair' . ($s['qty'] === 1 ? '' : 's')
                                              . ($flag === 'OUT' ? ' - out of stock' : ($flag === 'LOW' ? ' - low stock' : '')); ?>
                                        <div class="size-cell <?= $cls ?>" title="<?= e($tip) ?>">
                                            <span class="sz">US <?= number_format($s['size'], 1) ?></span>
                                            <span class="qt"><?= $s['qty'] ?></span>
                                            <span class="flag"><?= $flag ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <button class="btn btn-outline-secondary btn-sm" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#edit-<?= $shoeId ?>"
                                            aria-expanded="false" aria-controls="edit-<?= $shoeId ?>">Edit stock</button>
                                    <?php if ($isAdmin): ?>
                                    <button class="btn btn-outline-danger btn-sm btn-delete" type="button"
                                            data-id="<?= $shoeId ?>" data-name="<?= e($shoe['name']) ?>">Delete</button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">No sizes recorded</span>
                                <?php if ($isAdmin): ?>
                                <div class="mt-2"><button class="btn btn-outline-danger btn-sm btn-delete" type="button"
                                        data-id="<?= $shoeId ?>" data-name="<?= e($shoe['name']) ?>">Delete</button></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?= $shoe['total'] ?></td>
                    </tr>

                    <?php if ($shoe['sizes']): ?>
                    <tr class="collapse" id="edit-<?= $shoeId ?>">
                        <td colspan="5" class="bg-light">
                            <form method="post" action="admin.php" class="p-2 stock-form">
                                <input type="hidden" name="action" value="update_stock">
                                <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                <input type="hidden" name="shoe_id" value="<?= $shoeId ?>">
                                <p class="small fw-semibold mb-2">Update quantities for <?= e($shoe['name']) ?></p>
                                <div class="row g-2">
                                    <?php foreach ($shoe['sizes'] as $s): $lbl = 'US ' . number_format($s['size'], 1); ?>
                                        <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                            <label class="form-label small mb-0" for="q<?= $s['id'] ?>"><?= $lbl ?></label>
                                            <div class="input-group input-group-sm">
                                                <button type="button" class="btn btn-outline-secondary step-btn" data-delta="-1" aria-label="Decrease <?= $lbl ?>">&minus;</button>
                                                <input type="number" min="0" max="100000" step="1" inputmode="numeric"
                                                       class="form-control text-center qty-input" id="q<?= $s['id'] ?>"
                                                       name="qty[<?= $s['id'] ?>]" value="<?= $s['qty'] ?>" data-original="<?= $s['qty'] ?>">
                                                <button type="button" class="btn btn-outline-secondary step-btn" data-delta="1" aria-label="Increase <?= $lbl ?>">+</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-3">
                                    <button type="submit" class="btn btn-primary btn-sm" data-save disabled>
                                        Save changes <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" class="btn btn-link btn-sm" data-reset>Reset</button>
                                    <span class="small text-muted" data-changed-label aria-live="polite">No changes yet</span>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php if ($isAdmin): ?>
<!-- Delete confirmation (admin only) -->
<div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="admin.php" class="modal-content">
            <input type="hidden" name="action" value="delete_shoe">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="shoe_id" id="delete-id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="delete-title">Delete this shoe?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong id="delete-name"></strong> and all of its size stock will be permanently removed.</p>
                <p class="small text-muted mb-0">This can't be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete shoe</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php render_scripts(); ?>
<script>
(function () {
    /* ---------- Filtering + sorting (client-side, instant) ---------- */
    const table  = document.getElementById('stock-table');
    if (!table) return;
    const groups = Array.from(table.querySelectorAll('tbody.shoe-group'));
    const q = document.getElementById('filter-q'), cat = document.getElementById('filter-cat'),
          status = document.getElementById('filter-status'), sortSel = document.getElementById('sort'),
          noRes = document.getElementById('no-results'), count = document.getElementById('result-count');

    const sorters = {
        'name':       (a, b) => a.dataset.name.localeCompare(b.dataset.name),
        'out-desc':   (a, b) => (+b.dataset.out - +a.dataset.out) || a.dataset.name.localeCompare(b.dataset.name),
        'stock-asc':  (a, b) => (+a.dataset.total - +b.dataset.total) || a.dataset.name.localeCompare(b.dataset.name),
        'price-asc':  (a, b) => +a.dataset.price - +b.dataset.price,
        'price-desc': (a, b) => +b.dataset.price - +a.dataset.price
    };

    function apply() {
        const term = q.value.trim().toLowerCase();
        let shown = 0;
        groups.forEach(function (g) {
            const d = g.dataset;
            let ok = !term || d.search.indexOf(term) !== -1;
            if (ok && cat.value) ok = d.category === cat.value;
            if (ok && status.value === 'out') ok = +d.out > 0;
            if (ok && status.value === 'low') ok = +d.low > 0;
            if (ok && status.value === 'ok')  ok = +d.out === 0 && +d.low === 0;
            g.hidden = !ok;
            if (ok) shown++;
        });
        groups.slice().sort(sorters[sortSel.value]).forEach(function (g) { table.appendChild(g); });
        noRes.hidden = shown !== 0;
        count.textContent = 'Showing ' + shown + ' of ' + groups.length + ' shoe' + (groups.length === 1 ? '' : 's');
    }
    [q, cat, status, sortSel].forEach(function (el) { el.addEventListener('input', apply); });
    document.getElementById('clear-filters').addEventListener('click', function () {
        q.value = ''; cat.value = ''; status.value = ''; sortSel.value = 'name'; apply(); q.focus();
    });
    document.querySelectorAll('[data-quick-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            status.value = btn.dataset.quickFilter;
            sortSel.value = 'out-desc';
            apply();
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    document.addEventListener('keydown', function (ev) {
        const tag = (ev.target.tagName || '').toLowerCase();
        if (ev.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select') { ev.preventDefault(); q.focus(); }
    });
    apply();

    /* ---------- Stock editor: steppers, change tracking, loading state ---------- */
    document.querySelectorAll('form.stock-form').forEach(function (form) {
        const inputs = Array.from(form.querySelectorAll('.qty-input'));
        const save = form.querySelector('[data-save]');
        const label = form.querySelector('[data-changed-label]');

        function refresh() {
            let changed = 0;
            inputs.forEach(function (i) {
                const isChanged = i.value !== '' && i.value !== i.dataset.original;
                i.classList.toggle('is-changed', isChanged);
                if (isChanged) changed++;
            });
            save.disabled = changed === 0;
            label.textContent = changed === 0 ? 'No changes yet' : changed + ' size' + (changed === 1 ? '' : 's') + ' changed';
        }
        form.addEventListener('input', function (ev) {
            if (ev.target.classList.contains('qty-input') && +ev.target.value < 0) ev.target.value = 0;
            refresh();
        });
        form.addEventListener('click', function (ev) {
            const step = ev.target.closest('.step-btn');
            if (step) {
                const input = step.parentElement.querySelector('.qty-input');
                input.value = Math.max(0, (parseInt(input.value, 10) || 0) + parseInt(step.dataset.delta, 10));
                refresh();
            }
            if (ev.target.closest('[data-reset]')) {
                inputs.forEach(function (i) { i.value = i.dataset.original; });
                refresh();
            }
        });
        form.addEventListener('submit', function () {
            save.classList.add('is-loading');
            setTimeout(function () { save.disabled = true; }, 0);
        });
    });

    /* ---------- Delete confirmation (admins) ---------- */
    const modalEl = document.getElementById('delete-modal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        document.querySelectorAll('.btn-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('delete-id').value = btn.dataset.id;
                document.getElementById('delete-name').textContent = btn.dataset.name;
                modal.show();
            });
        });
    }
})();
</script>
</body>
</html>
