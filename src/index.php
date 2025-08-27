<?php
// index.php
declare(strict_types=1);

// === Конфиг ===
const BX_WEBHOOK          = 'https://b24-7d59hz.bitrix24.ru/rest/1/lcupf1tphfngxpnt'; // <<-- замени
const DEFAULT_ASSIGNED_ID = 1;
const DEFAULT_SOURCE_ID   = 'WEB';
const DEFAULT_DEAL_CAT_ID = 0;
const DEFAULT_DEAL_STAGE  = 'NEW';
const DEFAULT_CURRENCY    = 'RUB';

require_once __DIR__ . '/BitrixClient.php';

function old(string $key, $default = '')
{
    return htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function oldArr(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [$v];
}
function checked($cond): string { return $cond ? 'checked' : ''; }
function selected($cond): string { return $cond ? 'selected' : ''; }

// Справочники (под твои enum VALUES, маппятся по строкам)
$WORK_TYPES = ['Проектирование','Вентиляция и кондиционирование','Отопление','Водоснабжение','Электрика'];
$PLACE_TYPES = ['Квартира','Дом','Офис','Производство'];

// Обработка сабмита
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $bx = new BitrixClient(BX_WEBHOOK);

        $mode         = $_POST['mode']         ?? 'lead';     // lead|deal
        $subjectType  = $_POST['subject_type'] ?? 'person';   // person|company

        // subject
        $subject = [];
        if ($subjectType === 'company') {
            $subject = [
                'type'          => 'company',
                'title'         => trim((string)($_POST['company_title'] ?? '')),
                'inn'           => trim((string)($_POST['inn'] ?? '')),
                'phone'         => trim((string)($_POST['phone'] ?? '')),
                'email'         => trim((string)($_POST['email'] ?? '')),
                'legal_address' => trim((string)($_POST['legal_address'] ?? '')),
            ];
            if ($subject['title'] === '') {
                throw new InvalidArgumentException('Укажи название компании.');
            }
        } else {
            $subject = [
                'name'  => trim((string)($_POST['name'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
            ];
            if ($subject['name'] === '') {
                throw new InvalidArgumentException('Имя обязательно.');
            }
        }

        // data (UF)
        $data = [
            'work_types'      => array_values(array_intersect($WORK_TYPES, oldArr('work_types'))),
            'place_type'      => in_array(($_POST['place_type'] ?? ''), $PLACE_TYPES, true) ? $_POST['place_type'] : null,
            'area'            => $_POST['area']   ?? null,
            'amount'          => $_POST['amount'] ?? null,
            'has_design'      => isset($_POST['has_design']),
            'payment_purpose' => $_POST['payment_purpose'] ?? null,
            'currency'        => $_POST['currency'] ?? DEFAULT_CURRENCY,
            // 'UF_CRM_...' => $_POST['...'], // если захочешь передавать дополнительные UF
        ];

        // extra
        $extra = [
            'ASSIGNED_BY_ID' => (int)($_POST['assigned_by_id'] ?? DEFAULT_ASSIGNED_ID),
            'SOURCE_ID'      => $_POST['source_id'] ?? DEFAULT_SOURCE_ID,
        ];

        $title = trim((string)($_POST['title'] ?? 'Заявка с формы'));

        if ($mode === 'deal') {
            // Параметры воронки сделки
            $extra['CATEGORY_ID'] = (int)($_POST['deal_category_id'] ?? DEFAULT_DEAL_CAT_ID);
            $extra['STAGE_ID']    = $_POST['deal_stage_id'] ?? DEFAULT_DEAL_STAGE;
            $result = $bx->createDefaultDealSafe($title, $subject, $data, $extra);
        } else {
            // Лид всегда первичный (твоя версия метода не создаёт контакт/компанию)
            // Можно подставить статус, если нужно: $extra['STATUS_ID'] = 'NEW';
            $result = $bx->createDefaultLeadSafe($title, $subject, $data, $extra);
        }

        if (!($result['ok'] ?? false)) {
            $error = $result['error'] ?? 'Unknown error';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CRM форма → Bitrix</title>
<style>
  :root{
    --bg:#0f1115; --panel:#151823; --muted:#9aa4b2; --text:#e8eef7; --acc:#6ea8fe; --acc2:#22d3ee; --danger:#ef4444; --ok:#10b981;
    --ring: 0 0 0 2px rgba(110,168,254,.35);
  }
  *{box-sizing:border-box}
  body{margin:0;background:linear-gradient(180deg,#0d1017,#0b0e13 60%);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
  .container{max-width:980px;margin:40px auto;padding:0 16px}
  .card{background:linear-gradient(180deg,#161a24,#121521);border:1px solid #232836;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  .card .hd{padding:18px 20px;border-bottom:1px solid #232836;display:flex;align-items:center;gap:12px}
  .badge{font-size:12px;padding:2px 8px;border-radius:999px;background:#1f2432;color:#8aa0b2;border:1px solid #2a3142}
  .body{padding:20px}
  .row{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
  .col-6{grid-column:span 6} .col-12{grid-column:span 12} .col-4{grid-column:span 4} .col-3{grid-column:span 3} .col-8{grid-column:span 8}
  @media (max-width:860px){ .col-6,.col-4,.col-3,.col-8{grid-column:span 12} }
  label{display:block;font-weight:600;margin:10px 0 6px;color:#b8c4d6}
  input[type=text],input[type=number],select,textarea{
    width:100%; padding:12px 14px; background:#0f1320; border:1px solid #232836; color:var(--text); border-radius:12px; outline:none;
  }
  textarea{min-height:84px;resize:vertical}
  input:focus,select:focus,textarea:focus{box-shadow:var(--ring);border-color:#2f71ff}
  .hint{color:var(--muted);font-size:12px;margin-top:6px}
  .switch{display:inline-flex;gap:10px;background:#0f1320;border:1px solid #232836;padding:6px;border-radius:12px}
  .switch input{display:none}
  .pill{padding:8px 12px;border-radius:8px;border:1px solid transparent;cursor:pointer;color:#b8c4d6}
  .switch input:checked + .pill{background:#173059;border-color:#274a8a;color:#cfe3ff}
  .checks{display:flex;flex-wrap:wrap;gap:8px}
  .chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #273049;background:#0f1320;border-radius:999px}
  .chip input{accent-color:#79a6ff}
  .actions{display:flex;gap:12px;align-items:center;margin-top:10px}
  .btn{appearance:none;border:1px solid #2b3350;background:#1a2140;color:#e7f0ff;border-radius:12px;padding:12px 16px;font-weight:700;cursor:pointer}
  .btn:hover{filter:brightness(1.08)}
  .btn.primary{background:linear-gradient(90deg,#2b67f6,#15b4f1); border:none}
  .note{margin:16px 0;padding:12px 14px;border-radius:12px}
  .note.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35)}
  .note.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35)}
  .gridline{border-top:1px dashed #233047;margin:18px 0}
  .two{display:flex;gap:12px;flex-wrap:wrap}
  .two > * {flex:1}
  .muted{color:#97a3b7}
  .footer{opacity:.7;font-size:12px;margin-top:14px}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <div class="hd">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 3h18v4H3V3Zm0 7h18v11H3V10Zm4 3v5h10v-5H7Z" stroke="#6ea8fe" stroke-width="1.4"/></svg>
      <div style="font-weight:800">CRM форма → Bitrix</div>
      <div class="badge">lead / deal</div>
    </div>
    <div class="body">
      <?php if ($result && ($result['ok'] ?? false)): ?>
        <div class="note ok">Готово. ID = <b><?= (int)$result['id'] ?></b></div>
      <?php elseif ($error): ?>
        <div class="note err">Ошибка: <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off" id="crmForm">
        <div class="row">
          <div class="col-6">
            <label>Что создаём</label>
            <div class="switch">
              <label>
                <input type="radio" name="mode" value="lead" <?= checked(($_POST['mode'] ?? 'lead') === 'lead') ?> />
                <span class="pill">Лид</span>
              </label>
              <label>
                <input type="radio" name="mode" value="deal" <?= checked(($_POST['mode'] ?? '') === 'deal') ?> />
                <span class="pill">Сделка</span>
              </label>
            </div>
          </div>
          <div class="col-6">
            <label>Кто вы</label>
            <div class="switch">
              <label>
                <input type="radio" name="subject_type" value="person" <?= checked(($_POST['subject_type'] ?? 'person') === 'person') ?> />
                <span class="pill">Физлицо</span>
              </label>
              <label>
                <input type="radio" name="subject_type" value="company" <?= checked(($_POST['subject_type'] ?? '') === 'company') ?> />
                <span class="pill">Юрлицо</span>
              </label>
            </div>
          </div>

          <div class="col-12"><div class="gridline"></div></div>

          <div class="col-6 person-only">
            <label>Имя*</label>
            <input type="text" name="name" placeholder="Иван" value="<?= old('name') ?>">
          </div>

          <div class="col-6 company-only">
            <label>Название компании*</label>
            <input type="text" name="company_title" placeholder='ООО "Рога"' value="<?= old('company_title') ?>">
          </div>

          <div class="col-6">
            <label>Телефон</label>
            <input type="text" name="phone" placeholder="+7 999 111-22-33" value="<?= old('phone') ?>">
          </div>
          <div class="col-6">
            <label>Email</label>
            <input type="text" name="email" placeholder="user@example.com" value="<?= old('email') ?>">
          </div>

          <div class="col-6 company-only">
            <label>ИНН</label>
            <input type="text" name="inn" placeholder="7701234567" value="<?= old('inn') ?>">
          </div>
          <div class="col-6 company-only">
            <label>Юридический адрес</label>
            <input type="text" name="legal_address" placeholder="123456, Россия, Москва, ул. ..." value="<?= old('legal_address') ?>">
          </div>

          <div class="col-12"><div class="gridline"></div></div>

          <div class="col-12">
            <label>Виды работ</label>
            <div class="checks">
              <?php foreach ($WORK_TYPES as $w): ?>
                <label class="chip">
                  <input type="checkbox" name="work_types[]" value="<?= htmlspecialchars($w) ?>"
                         <?= checked(in_array($w, oldArr('work_types'), true)) ?> >
                  <span><?= htmlspecialchars($w) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="hint">Можно несколько.</div>
          </div>

          <div class="col-4">
            <label>Тип помещения</label>
            <select name="place_type">
              <option value="">— не выбрано —</option>
              <?php foreach ($PLACE_TYPES as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>" <?= selected(($p === ($_POST['place_type'] ?? ''))) ?>><?= htmlspecialchars($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-4">
            <label>Площадь (м²)</label>
            <input type="text" name="area" placeholder="85,2" value="<?= old('area') ?>">
          </div>

          <div class="col-4">
            <label>Есть ДП/планировка</label>
            <div class="switch">
              <label>
                <input type="checkbox" name="has_design" value="1" <?= checked(isset($_POST['has_design'])) ?> />
                <span class="pill">Да</span>
              </label>
            </div>
          </div>

          <div class="col-4">
            <label>Сумма</label>
            <input type="text" name="amount" placeholder="120000" value="<?= old('amount') ?>">
          </div>
          <div class="col-4">
            <label>Валюта</label>
            <select name="currency">
              <?php foreach (['RUB','USD','EUR'] as $cur): ?>
                <option value="<?= $cur ?>" <?= selected(($cur === ($_POST['currency'] ?? DEFAULT_CURRENCY))) ?>><?= $cur ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4 company-only">
            <label>Назначение платежа</label>
            <input type="text" name="payment_purpose" placeholder="Оплата по договору №..." value="<?= old('payment_purpose') ?>">
          </div>

          <div class="col-12"><div class="gridline"></div></div>

          <div class="col-6">
            <label>Заголовок</label>
            <input type="text" name="title" placeholder="Заявка с формы" value="<?= old('title','Заявка с формы') ?>">
          </div>
          <div class="col-3">
            <label>Ответственный (ID)</label>
            <input type="number" name="assigned_by_id" min="1" value="<?= old('assigned_by_id', (string)DEFAULT_ASSIGNED_ID) ?>">
          </div>
          <div class="col-3">
            <label>Источник</label>
            <input type="text" name="source_id" value="<?= old('source_id', DEFAULT_SOURCE_ID) ?>">
          </div>

          <div class="col-6 deal-only">
            <label>Категория сделки</label>
            <input type="number" name="deal_category_id" min="0" value="<?= old('deal_category_id', (string)DEFAULT_DEAL_CAT_ID) ?>">
          </div>
          <div class="col-6 deal-only">
            <label>Стадия сделки</label>
            <input type="text" name="deal_stage_id" value="<?= old('deal_stage_id', DEFAULT_DEAL_STAGE) ?>">
          </div>

          <div class="col-12 actions">
            <button class="btn primary" type="submit">Отправить в Bitrix</button>
            <div class="muted">мы не создаём контакт/компанию для ЛИДА — первичный по инструкции</div>
          </div>
        </div>
      </form>

      <div class="footer">UI — без внешних библиотек. Поля маппятся по VALUE, Bitrix сам резолвит ID через ваш класс.</div>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('crmForm');
  const toggle = () => {
    const mode = form.mode.value; // lead|deal
    const subj = form.subject_type.value; // person|company
    document.querySelectorAll('.deal-only').forEach(el => el.style.display = (mode==='deal') ? '' : 'none');
    document.querySelectorAll('.person-only').forEach(el => el.style.display = (subj==='person') ? '' : 'none');
    document.querySelectorAll('.company-only').forEach(el => el.style.display = (subj==='company') ? '' : 'none');
  };
  form.mode.forEach ? form.mode.forEach(r => r.addEventListener('change', toggle))
                    : Array.from(form.querySelectorAll('input[name="mode"]')).forEach(r=>r.addEventListener('change', toggle));
  Array.from(form.querySelectorAll('input[name="subject_type"]')).forEach(r=>r.addEventListener('change', toggle));
  toggle();
})();
</script>
</body>
</html>
