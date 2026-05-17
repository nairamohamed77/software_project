<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('FamilyProxy');
require_once dirname(__DIR__, 2) . '/models/FamilyProxy.php';
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/models/Visit.php';
require_once dirname(__DIR__, 2) . '/models/ServiceCategory.php';
require_once dirname(__DIR__, 2) . '/models/Pal.php';

$palCatalog = Pal::catalogForBookingWithBadges();

$linked = FamilyProxy::linkedSeniorsWithProfiles(currentUserId());

function cn_proxy_find_linked(array $linked, int $seniorId): ?array {
    foreach ($linked as $row) {
        if ((int) ($row['senior_ID'] ?? 0) === $seniorId) {
            return $row;
        }
    }

    return null;
}

$defaultSid = (int) (($linked[0]['senior_ID'] ?? 0));
$requestedSid = (int) ($_GET['senior_id'] ?? 0);
$activeSeniorId = ($requestedSid > 0 && FamilyProxy::proxyCanManageSenior(currentUserId(), $requestedSid))
    ? $requestedSid
    : $defaultSid;

$activeRow = cn_proxy_find_linked($linked, $activeSeniorId);
$balanceBefore = $activeRow !== null ? (int) ($activeRow['points_balance'] ?? 0) : 0;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['finalize'] ?? '') === '1') {
    try {
        $bookSeniorId = (int) ($_POST['booking_senior_id'] ?? 0);
        if (!FamilyProxy::proxyCanManageSenior(currentUserId(), $bookSeniorId)) {
            throw new RuntimeException('You cannot book for that senior.');
        }
        $seniorUserId = Senior::seniorUserIdFromSeniorRow($bookSeniorId);
        if ($seniorUserId === null || $seniorUserId <= 0) {
            throw new RuntimeException('Senior profile not found.');
        }

        $cat = (int) ($_POST['category_id'] ?? 0);
        $pal = (int) ($_POST['pal_id'] ?? 0);
        $startDate = trim((string) ($_POST['date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $notesMain = trim((string) ($_POST['notes_main'] ?? ''));
        $notesSpec = trim((string) ($_POST['notes_special'] ?? ''));
        if ($pal <= 0 || $cat <= 0 || $startDate === '' || $startTime === '' || $endTime === '') {
            throw new RuntimeException('Please complete all booking steps.');
        }
        $start = $startDate . ' ' . $startTime . ':00';
        $end = $startDate . ' ' . $endTime . ':00';

        require_once dirname(__DIR__, 2) . '/models/Pal.php';
        $prof = Pal::profileByPalId($pal);
        if ($prof === null) {
            throw new RuntimeException('Selected Pal is unavailable.');
        }
        if (!Pal::canBeBookedNow($pal)) {
            throw new RuntimeException('Selected Pal is currently on another active task. Please choose a different Pal.');
        }
        $palUser = (int) ($prof['User_ID'] ?? 0);
        if ($palUser <= 0) {
            throw new RuntimeException('Invalid Pal record.');
        }
        $svc = ServiceCategory::byId($cat);
        $cost = (int) (($svc !== null ? (int) ($svc['base_points_cost'] ?? 0) : 0));

        Visit::createBooking(
            $bookSeniorId,
            $seniorUserId,
            $pal,
            $palUser,
            $cat,
            $start,
            $end,
            $cost,
            ['n1' => $notesMain, 'n2' => $notesSpec]
        );
        header('Location: ' . carenest_url('views/proxy/visit_history.php?booked=1'));
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$categories = ServiceCategory::active();

$pageTitle = 'Book a Visit — Family Proxy';
$active = 'book';

$proxyUser = User::findById(currentUserId()) ?: [];
$cnContextBar = [
    'role_label' => 'Family proxy',
    'user_name' => trim((string) ($proxyUser['Fname'] ?? '') . ' ' . (string) ($proxyUser['Lname'] ?? '')),
    'senior_line' => '',
    'points' => $balanceBefore,
    'points_label' => 'Household SilverPoints',
];
if ($activeRow !== null) {
    $cnContextBar['senior_line'] = 'Booking for: ' . trim((string) ($activeRow['Fname'] ?? '') . ' ' . (string) ($activeRow['Lname'] ?? ''))
        . (($activeRow['relationship_type'] ?? '') !== '' ? ' · ' . (string) $activeRow['relationship_type'] : '');
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_proxy.php'; ?>

<main class="main-content">
    <?php include dirname(__DIR__) . '/layouts/context_bar.php'; ?>

    <?php if (!$linked): ?>
        <div class="cn-card cn-card-body"><?= e('No linked seniors yet. Link to a senior account before booking visits.') ?></div>
    <?php else: ?>
        <div class="mx-auto cn-card cn-card-body" style="max-width: 880px;">
            <h1 class="h4 mb-4"><?= e('Schedule help for your senior') ?></h1>
            <p class="small mb-4" style="color:var(--text-secondary);"><?= e('Bookings use your senior\'s SilverPoints balance.') ?></p>

            <?php if ($error !== ''): ?>
                <div class="alert-cn alert-cn-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if (count($linked) > 1): ?>
                <div class="mb-4">
                    <label class="cn-label" for="proxy-senior-picker"><?= e('Which senior is this visit for?') ?></label>
                    <select class="cn-input" id="proxy-senior-picker">
                        <?php foreach ($linked as $r): ?>
                            <?php
                            $sidOpt = (int) ($r['senior_ID'] ?? 0);
                            $sel = $sidOpt === $activeSeniorId ? ' selected' : '';
                            $lab = trim((string) ($r['Fname'] ?? '') . ' ' . (string) ($r['Lname'] ?? ''));
                            ?>
                            <option value="<?= $sidOpt ?>"<?= $sel ?>><?= e($lab) ?> — <?= (int) ($r['points_balance'] ?? 0) ?> <?= e('SilverPoints') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <script>
                document.getElementById('proxy-senior-picker').addEventListener('change', function () {
                    window.location.href = '<?= carenest_url('views/proxy/book_visit.php') ?>?senior_id=' + encodeURIComponent(this.value);
                });
                </script>
            <?php endif; ?>

            <form method="post" id="booking-form">
                <input type="hidden" name="finalize" value="1" id="finalize_flag">
                <input type="hidden" name="booking_senior_id" value="<?= (int) $activeSeniorId ?>">
                <input type="hidden" name="category_id" id="hid_category">
                <input type="hidden" name="pal_id" id="hid_pal">

                <section class="mb-5">
                    <h2 class="h5 mb-3"><?= e('Step 1 — Pick a service') ?></h2>
                    <div class="row row-cols-1 row-cols-md-2 g-3" id="category-grid">
                        <?php foreach ($categories as $c): ?>
                            <div class="col">
                                <div class="service-pick-card" data-id="<?= (int) ($c['id'] ?? 0) ?>">
                                    <div class="d-flex gap-3">
                                        <?php
                                        $iconCls = trim((string) ($c['icon'] ?? ''));
                                        $iconFull = ($iconCls === '') ? 'fa-solid fa-heart' : ((str_contains($iconCls, ' ') || preg_match('#^fa-(brand|solid|regular|thin)\b#i', $iconCls)) ? $iconCls : 'fa-solid ' . $iconCls);
                                        ?>
                                        <div style="font-size:2rem;color:var(--accent-strong);" aria-hidden="true"><i class="<?= e($iconFull) ?>"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold"><?= e((string) ($c['category_name'] ?? '')) ?></div>
                                            <div style="color:var(--text-secondary);"><?= (int) ($c['base_points_cost'] ?? 0) ?> <?= e('SilverPoints') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="mb-5 row g-3">
                    <div class="col-md-6">
                        <label class="cn-label"><?= e('Step 2 — Date') ?></label>
                        <input type="date" class="cn-input" name="date" id="visit_date" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="cn-label"><?= e('Start time') ?></label>
                        <input type="time" class="cn-input" name="start_time" id="start_time" required>
                    </div>
                    <div class="col-md-3">
                        <label class="cn-label"><?= e('Estimated end') ?></label>
                        <input type="time" class="cn-input" name="end_time" id="end_time" readonly>
                    </div>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3"><?= e('Step 3 — Tell us how we can help') ?></h2>
                    <textarea class="cn-input" name="notes_main" rows="3" placeholder="<?= e('Describe what your senior needs…') ?>" required></textarea>
                    <textarea class="cn-input mt-3" name="notes_special" rows="2" placeholder="<?= e('Special instructions (optional)') ?>" style="min-height:92px;"></textarea>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3"><?= e('Step 4 — Choose a Pal') ?></h2>
                    <label class="cn-label" for="pal-select-native"><?= e('Select Pal (required)') ?></label>
                    <select id="pal-select-native" class="cn-input mb-3" autocomplete="off">
                        <option value=""><?= e('— Choose a Pal —') ?></option>
                        <?php foreach ($palCatalog as $p): ?>
                            <?php $pid = (int) ($p['pal_ID'] ?? 0); ?>
                            <?php $disabled = ((int) ($p['has_active_assignment'] ?? 0) === 1) ? ' disabled' : ''; ?>
                            <option value="<?= $pid ?>"<?= $disabled ?>><?= e(Pal::palPickerOptionLabel($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small mb-3" id="pal-picker-hint" style="color:var(--text-secondary);"><?= e('Unavailable Pals are currently on another mission and cannot be selected until they finish.') ?></div>
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h3 class="h6 mb-0"><?= e('Or pick from cards') ?></h3>
                        <button type="button" id="reload-pals" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Reload list') ?></button>
                    </div>
                    <div id="pal-list" class="row row-cols-1 g-3"></div>
                    <input type="hidden" id="selected_pal_id" value="">
                </section>

                <div id="booking-inline-err" class="alert-cn alert-cn-danger mb-3" style="display:none;"></div>

                <section class="cn-card cn-card-body mb-5 cn-summary-soft" id="summary-box">
                    <h3 class="h6"><?= e('Cost Summary') ?></h3>
                    <div><strong><?= e('Service:') ?></strong> <span id="sum_cat_name">—</span></div>
                    <div><strong><?= e('Duration') ?>:</strong> <span id="sum_duration"><?= e('1 hour baseline') ?></span></div>
                    <div><strong><?= e('Estimated cost') ?>:</strong> <span id="sum_cost">0</span> <?= e('SilverPoints') ?></div>
                    <div><strong><?= e('Senior\'s balance') ?>:</strong> <?= (int) $balanceBefore ?> <?= e('SilverPoints') ?></div>
                    <div><strong><?= e('After booking') ?>:</strong> <span id="sum_after"><?= (int) $balanceBefore ?></span> <?= e('SilverPoints') ?></div>
                </section>

                <button type="submit" class="cn-btn cn-btn-primary cn-btn-block cn-btn-lg" id="btn-submit-booking"><?= e('Confirm booking') ?></button>
            </form>
        </div>
    <?php endif; ?>
</main>

<script>
(() => {
  const baseBal = <?= (int) $balanceBefore ?>;
  const epCost = "<?= carenest_url('ajax/get_points_cost.php') ?>";
  const epPals = "<?= carenest_url('ajax/get_available_pals.php') ?>";
  let selCat = 0;
  let selCost = 0;

  const grid = document.getElementById('category-grid');
  if (!grid) return;

  const hidCat = document.getElementById('hid_category');
  const hidPal = document.getElementById('hid_pal');
  const palList = document.getElementById('pal-list');
  const reloadPalsBtn = document.getElementById('reload-pals');
  const durationLabel = document.getElementById('sum_duration');
  const palNative = document.getElementById('pal-select-native');
  const inlineErr = document.getElementById('booking-inline-err');

  function showBookErr(msg) {
    if (!inlineErr) { alert(msg); return; }
    inlineErr.style.display = msg ? 'block' : 'none';
    inlineErr.textContent = msg || '';
  }

  if (palNative && hidPal) {
    palNative.addEventListener('change', () => {
      hidPal.value = palNative.value || '';
      document.getElementById('selected_pal_id').value = palNative.value || '';
      showBookErr('');
    });
  }

  grid.addEventListener('click', (ev) => {
    const card = ev.target.closest('.service-pick-card');
    if (!card) return;
    [...grid.querySelectorAll('.service-pick-card')].forEach(c=>c.classList.remove('selected'));
    card.classList.add('selected');
    selCat = parseInt(card.dataset.id||'0',10);
    hidCat.value = String(selCat);
    fetch(epCost+'?category_id='+encodeURIComponent(selCat), {credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (j.cost !== undefined){ selCost = parseInt(j.cost,10)||0;
          document.getElementById('sum_cat_name').textContent=j.category_name||'';
          document.getElementById('sum_cost').textContent = String(selCost);
          document.getElementById('sum_after').textContent = String(baseBal-selCost);
        }
      })
      .catch(()=>{});

    document.getElementById('visit_date').dispatchEvent(new Event('change'));
    reloadPals();
  });

  function calcEnd(startVal){
    if (!startVal) return;
    const [h,m] = startVal.split(':').map(x=>parseInt(x,10));
    const dt = new Date(); dt.setHours(h,m,0,0); dt.setMinutes(dt.getMinutes()+60);
    const pad = (n)=> (n<10?'0':'')+n;
    document.getElementById('end_time').value = pad(dt.getHours())+':'+pad(dt.getMinutes());
    durationLabel.textContent = '~1 hour (+/- travel)';
  }

  document.getElementById('start_time').addEventListener('change', (e)=>{
    calcEnd(e.target.value||'09:00');
  });

  function reloadPals(){
    palList.innerHTML='<div style="color:var(--text-secondary);">Loading Pals…</div>';
    fetch(epPals+'?category_id='+encodeURIComponent(selCat||0)+'&scheduled_start='+encodeURIComponent(new Date().toISOString()),
      {credentials:'same-origin'})
      .then(r=>r.json())
      .then(data=>{
        palList.innerHTML='';
        const pals = data.pals||[];
        if (!pals.length) {
          palList.innerHTML='<div class="alert-cn alert-cn-warning mb-0"><?= e('No Pal profiles found right now. Use the dropdown above if any appear there.') ?></div>';
          return;
        }
        pals.forEach(p=>{
          const stars = '\u2605'.repeat(Math.max(1, Math.round(p.rating_avg||5)))+'\u2606'.repeat(Math.max(0,5-Math.round(p.rating_avg||5)));
          const badges = (p.badges||[]).map(b=>'<span class="badge-status badge-approved me-1">'+escapeHtml(b)+'</span>').join('');
          const busy = !p.is_available ? ' <span class="badge-status badge-pending">calendar busy</span>' : '';
          const taskPend = parseInt(p.has_active_assignment||0, 10) === 1 ? ' <span class="badge-status badge-pending">pending task</span>' : '';
          const accLow = String(p.account_status||'').toLowerCase().trim();
          const accPend = (!p.user_is_active || accLow !== 'active') ? ' <span class="badge-status badge-pending">account inactive</span>' : '';
          const disabled = parseInt(p.has_active_assignment||0, 10) === 1;
          const col = document.createElement('div'); col.className='col';
          col.innerHTML = `
            <div class="pal-pick-card cn-card cn-card-body d-flex gap-3 flex-wrap justify-content-between">
              <div>
                <div class="fw-bold">${escapeHtml(p.fname+' '+p.lname)}${taskPend}${accPend}${busy}</div>
                <div style="color:var(--text-secondary);" class="mb-2">${stars}</div>
                <div class="small mb-2">${badges}</div>
                <div style="color:var(--text-secondary);" class="small">Travel radius ~ ${p.travel_radius_km||0} km</div>
              </div>
              <div>
                <button type="button" class="cn-btn cn-btn-primary pal-select-btn" data-pal="${p.pal_ID}" ${disabled ? 'disabled' : ''}>${disabled ? 'Pending' : 'Select'}</button>
              </div>
            </div>`;
          palList.appendChild(col);
        });
        if (palNative) {
          if (hidPal.value && [...palNative.options].some(o=>String(o.value)===String(hidPal.value))) {
            palNative.value = hidPal.value;
          } else if (pals.length === 1 && parseInt(pals[0].has_active_assignment||0, 10) !== 1) {
            palNative.value = String(pals[0].pal_ID);
            hidPal.value = palNative.value;
            document.getElementById('selected_pal_id').value = palNative.value;
          }
        }
      })
      .catch(()=>{
        palList.innerHTML='<div class="alert-cn alert-cn-danger mb-0"><?= e('Could not load the Pal list. Use the dropdown above or try Reload list.') ?></div>';
      });
  }

  function escapeHtml(str){
    return String(str||'').replace(/[&<>"]+/g,function(s){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]||s;});
  }

  palList.addEventListener('click', (ev)=>{
    const btn = ev.target.closest('.pal-select-btn');
    if(!btn)return;
    const pid = btn.getAttribute('data-pal'); hidPal.value=pid;
    document.getElementById('selected_pal_id').value=pid;
    if (palNative && pid) { palNative.value = String(pid); }
    showBookErr('');
    [...palList.querySelectorAll('.pal-pick-card')].forEach(c=>{c.style.outline='none';});
    btn.closest('.pal-pick-card').style.outline='3px solid var(--border-focus)';
  });

  reloadPalsBtn.addEventListener('click', reloadPals);

  document.getElementById('booking-form').addEventListener('submit', (ev)=>{
    if (!hidCat.value) {
      ev.preventDefault();
      showBookErr('Please select a service category (Step 1).');
      return false;
    }
    if (!hidPal.value) {
      ev.preventDefault();
      showBookErr('Please select a Pal from the dropdown or the cards (Step 4).');
      return false;
    }
    showBookErr('');
    return true;
  });

  const firstCat = grid.querySelector('.service-pick-card');
  if (firstCat) {
    setTimeout(() => firstCat.click(), 0);
  }
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
