# BitrixClient — Документация (актуалка, `legal_address` — **строка**)

Мини-клиент к Bitrix24 через вебхук. Создаёт/находит контакт и/или компанию **без дублей**, создаёт **сделку**, проставляет UF-поля (включая множественные enum’ы и double), складывает юр.сводку в один текстовый UF. Переживает 429/временные фейлы. Возвращает аккуратный `['ok'=>..., ...]`.

---

## Что делает

* Создаёт/находит **Контакт** и/или **Компанию**:
  * Контакт — поиск по `PHONE`, затем `EMAIL`.
  * Компания — поиск по **ИНН** (если есть соответствующий UF на компании), затем `PHONE`, `EMAIL`, затем точный `TITLE`.
* Создаёт **Сделку** c привязками `CONTACT_ID` и/или `COMPANY_ID`.
* Заполняет UF-поля сделки:
  * `UF_CRM_1755625105555` — *Виды работ* (enum, multiple).
  * `UF_CRM_1755625205341` — *Тип помещения* (enum).
  * `UF_CRM_1755625259983` — *Площадь* (double).
  * `UF_CRM_1755635797930` — *Назначение платежа* (string).
  * `UF_CRM_1755634129009` — *Юр.сводка* (многострочный текст): **Компания / ИНН / Тел / Email / Юр. адрес (строка)**.
  * Любые другие `UF_CRM_*` из `$data` — **автоподхват**.
* Enum-fallback: если `crm.deal.fields` не отдаёт `items` для поля — принимает **только числовые ID**.
* Экспоненциальные **ретраи** при 429/квотах/временных косяках.
* Нормализация телефона/почты и чисел с запятыми.

---

## Требования

* PHP 8.0+
* Расширения: `curl`, `json`
* `mbstring`**не обязателен** (внутри безопасный lowercase).

---

## Инициализация

Создай входящий вебхук с правами CRM → получишь URL:

<pre class="overflow-visible!" data-start="1513" data-end="1611"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>$bx</span><span> = </span><span>new</span><span> </span><span>BitrixClient</span><span>(</span><span>'https://<portal>.bitrix24.ru/rest/<USER_ID>/<WEBHOOK_TOKEN>/'</span><span>);
</span></span></code></div></div></pre>

---

## Публичный метод

### `createDefaultDealSafe(string $title, array $subject, array $data = [], array $extra = []): array`

Один вызов — и готово: анти-дубли сущностей, создание сделки, UF’ы в бою.

**Возвращает:**

* Успех: `['ok' => true, 'id' => <dealId>]`
* Ошибка: `['ok' => false, 'error' => '<сообщение>']`

---

## Параметры

### `$title`

Заголовок сделки.

### `$subject` — кто к нам пришёл

Три сценария:

* **Контакт**:

  <pre class="overflow-visible!" data-start="2050" data-end="2171"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>[
    </span><span>'name'</span><span>  => </span><span>'Иван Петров'</span><span>,
    </span><span>'phone'</span><span> => </span><span>'+7 (999) 123-45-67'</span><span>,
    </span><span>'email'</span><span> => </span><span>'ivan@example.com'</span><span>
  ]
  </span></span></code></div></div></pre>
* **Компания** (определяется по `title`/`inn` или `type'=>'company'`):

  <pre class="overflow-visible!" data-start="2246" data-end="2600"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>[
    </span><span>'type'</span><span>          => </span><span>'company'</span><span>,        </span><span>// опционально</span><span>
    </span><span>'title'</span><span>         => </span><span>'ООО "Рога и Копыта"'</span><span>,
    </span><span>'inn'</span><span>           => </span><span>'7701234567'</span><span>,
    </span><span>'phone'</span><span>         => </span><span>'+7 (495) 111-22-33'</span><span>,
    </span><span>'email'</span><span>         => </span><span>'info@roga.ru'</span><span>,
    </span><span>'legal_address'</span><span> => </span><span>'123456, Россия, Московская обл., г. Химки, ул. Ленина, д. 10, оф. 25'</span><span> </span><span>// ТОЛЬКО СТРОКА</span><span>
  ]
  </span></span></code></div></div></pre>
* **Готовые ID** (не создаём новые):

  <pre class="overflow-visible!" data-start="2641" data-end="2698"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>[</span><span>'contact_id'</span><span> => </span><span>123</span><span>, </span><span>'company_id'</span><span> => </span><span>456</span><span>]
  </span></span></code></div></div></pre>

  Можно комбинировать (например, `company_id` + создать новый контакт по `name`).

### `$data` — бизнес-поля сделки

* `work_types` → `UF_CRM_1755625105555`*(enum multiple)*
  Принимает значения как **строки** (тексты пунктов), так и **ID** (числа), можно смешивать.
* `place_type` → `UF_CRM_1755625205341`*(enum, single)*.
* `area` → `UF_CRM_1755625259983`*(double, `"123,45"` ок)*.
* `payment_purpose` → `UF_CRM_1755635797930`*(string)*.
* `amount` → `OPPORTUNITY`*(сумма)*.
* `currency` → `CURRENCY_ID`*(по умолчанию `RUB`)*.

**Автоподхват:** любой `UF_CRM_*` ключ из `$data` улетит в сделку как есть (с маппингом типов).

**Юр.сводка в `UF_CRM_1755634129009`:**
Если есть компания (или признаки), кладётся многострочный текст:

<pre class="overflow-visible!" data-start="3442" data-end="3549"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre!"><span><span><span class="language-xml">Компания: <title</span></span><span>>
ИНН: </span><span><inn</span><span>>
Телефон: </span><span><phone</span><span>>
Email: </span><span><email</span><span>>
Юр. адрес: </span><span><legal_address</span><span>>   // строка
</span></span></code></div></div></pre>

### `$extra` — любые поля сделки Bitrix

Например:

<pre class="overflow-visible!" data-start="3602" data-end="3789"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>[
  </span><span>'CATEGORY_ID'</span><span>    => </span><span>0</span><span>,
  </span><span>'STAGE_ID'</span><span>       => </span><span>'NEW'</span><span>,
  </span><span>'ASSIGNED_BY_ID'</span><span> => </span><span>1</span><span>,
  </span><span>'SOURCE_ID'</span><span>      => </span><span>'WEB'</span><span>,
  </span><span>'UTM_SOURCE'</span><span>     => </span><span>'landing'</span><span>,
  </span><span>// можно и свои UF_CRM_* сюда</span><span>
]
</span></span></code></div></div></pre>

---

## Анти-дубли

### Контакт

1. `crm.contact.list` по `PHONE` (нормализованный).
2. Если нет — по `EMAIL` (lowercased).
3. Если не нашли — создаём `crm.contact.add`.

### Компания

1. Ищем UF компании для ИНН (по лейблам «ИНН/INN») и при наличии `inn` ищем по нему.
2. Если нет — по `PHONE`, затем `EMAIL`.
3. Если нет — по точному `TITLE`.
4. Не нашли — создаём `crm.company.add`. Если UF для ИНН обнаружен — записываем `inn` туда (для будущих дедупов).

---

## Поведение полей

* **Enum (списки):**
  * Если `crm.deal.fields` вернул `items` — можно передавать **строки** и/или **ID**.
  * Если `items` пуст/недоступен — принимаются **только числовые ID** (иначе ошибка).
* **Multiple:** массив строк/ID ок; внутри маппится в массив ID.
* **Double/Integer:**`"123,45"` → `123.45`. Пустые — не отправляем (для одиночного double вернётся `0.0`).
* **Телефон/Email:** телефон нормализуется (`+7XXXXXXXXXX` для РФ, иначе `+digits` если не с нуля); email `trim+lower`.
* **Валюта/Сумма:**`currency` по умолчанию `RUB`; `amount` проставится, если распарсили число.

---

## Примеры

### 1) Физлицо

<pre class="overflow-visible!" data-start="4899" data-end="5288"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>$res</span><span> = </span><span>$bx</span><span>-></span><span>createDefaultDealSafe</span><span>(
  </span><span>'Заявка (физик)'</span><span>,
  [</span><span>'name'</span><span>=></span><span>'Иван Петров'</span><span>,</span><span>'phone'</span><span>=></span><span>'+7 (999) 123-45-67'</span><span>,</span><span>'email'</span><span>=></span><span>'ivan@example.com'</span><span>],
  [
    </span><span>'work_types'</span><span> => [</span><span>'Проектирование'</span><span>,</span><span>'Электрика'</span><span>],
    </span><span>'place_type'</span><span> => </span><span>'Квартира'</span><span>,
    </span><span>'area'</span><span>       => </span><span>'123,45'</span><span>,
    </span><span>'amount'</span><span>     => </span><span>15000</span><span>,
    </span><span>'currency'</span><span>   => </span><span>'RUB'</span><span>,
  ],
  [</span><span>'CATEGORY_ID'</span><span>=></span><span>0</span><span>,</span><span>'STAGE_ID'</span><span>=></span><span>'NEW'</span><span>,</span><span>'ASSIGNED_BY_ID'</span><span>=></span><span>1</span><span>]
);
</span></span></code></div></div></pre>

### 2) Юрлицо (адрес — строка)

<pre class="overflow-visible!" data-start="5321" data-end="6072"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>$res</span><span> = </span><span>$bx</span><span>-></span><span>createDefaultDealSafe</span><span>(
  </span><span>'Заявка (юрлицо)'</span><span>,
  [
    </span><span>'type'</span><span>          => </span><span>'company'</span><span>,
    </span><span>'title'</span><span>         => </span><span>'ООО "Рога и Копыта"'</span><span>,
    </span><span>'inn'</span><span>           => </span><span>'7701234567'</span><span>,
    </span><span>'phone'</span><span>         => </span><span>'+7 (495) 111-22-33'</span><span>,
    </span><span>'email'</span><span>         => </span><span>'info@roga.ru'</span><span>,
    </span><span>'legal_address'</span><span> => </span><span>'123456, Россия, Московская обл., г. Москва, ул. Тверская, д. 1, оф. 2'</span><span>,
  ],
  [
    </span><span>'work_types'</span><span>      => [</span><span>44</span><span>, </span><span>52</span><span>], // можно ID
    </span><span>'place_type'</span><span>      => </span><span>60</span><span>,       // </span><span>"Производство"</span><span>
    </span><span>'area'</span><span>            => </span><span>560.0</span><span>,
    </span><span>'amount'</span><span>          => </span><span>350000</span><span>,
    </span><span>'currency'</span><span>        => </span><span>'RUB'</span><span>,
    </span><span>'payment_purpose'</span><span> => </span><span>'Оплата по договору №42 от 20.08.2025'</span><span>,
    // любые доп. UF_CRM_* сюда — автоподхват
  ],
  [</span><span>'CATEGORY_ID'</span><span>=></span><span>0</span><span>,</span><span>'STAGE_ID'</span><span>=></span><span>'NEW'</span><span>,</span><span>'ASSIGNED_BY_ID'</span><span>=></span><span>1</span><span>]
);
</span></span></code></div></div></pre>

### 3) Привязать к существующим ID

<pre class="overflow-visible!" data-start="6109" data-end="6309"><div class="contain-inline-size rounded-2xl relative bg-token-sidebar-surface-primary"><div class="sticky top-9"><div class="absolute end-0 bottom-0 flex h-9 items-center pe-2"><div class="bg-token-bg-elevated-secondary text-token-text-secondary flex items-center gap-4 rounded-sm px-2 font-sans text-xs"><span class="" data-state="closed"></span></div></div></div><div class="overflow-y-auto p-4" dir="ltr"><code class="whitespace-pre! language-php"><span><span>$res</span><span> = </span><span>$bx</span><span>-></span><span>createDefaultDealSafe</span><span>(
  </span><span>'Апселл'</span><span>,
  [</span><span>'contact_id'</span><span>=></span><span>123</span><span>, </span><span>'company_id'</span><span>=></span><span>456</span><span>],
  [</span><span>'amount'</span><span>=></span><span>9990</span><span>, </span><span>'currency'</span><span>=></span><span>'RUB'</span><span>],
  [</span><span>'CATEGORY_ID'</span><span>=></span><span>0</span><span>,</span><span>'STAGE_ID'</span><span>=></span><span>'NEW'</span><span>,</span><span>'ASSIGNED_BY_ID'</span><span>=></span><span>7</span><span>]
);
</span></span></code></div></div></pre>

---

## Ошибки (как выглядят)

* `['ok'=>false,'error'=>'Enum: items missing, provide numeric IDs']`
* `['ok'=>false,'error'=>'Contact name is required']`
* `['ok'=>false,'error'=>'Bitrix error: ...']`
* `['ok'=>false,'error'=>'cURL error (28): Operation timed out']`

Метод `createDefaultDealSafe`**не кидает исключения наружу** — возвращает объект с `ok=false`.

---

## Сеть, квоты, стабильность

* До **3 попыток** с экспоненциальной задержкой при:
  * HTTP 429,
  * `TOO_MANY_REQUESTS`, `METHOD_QUOTA_EXCEEDED`, `TEMPORARY_UNAVAILABLE`,
  * пустом ответе/битом JSON.
* На третью — фейл с внятным сообщением.

---

## Нюансы и советы

* `UF_CRM_1755634129009` — текст. Если сводка длинная, проверь лимит (часто 255 у строкового поля). Для больших объёмов лучше поле-текст (многострочное).
* Поиск компании по `TITLE` точный; если нужен LIKE — можно докрутить, но словишь мусор.
* Нормализация телефона базовая. Хочешь строго E.164 — используй `libphonenumber`.

---

## Changelog

* **Upd:**`legal_address` теперь **только строка**. Поддержка объектного адреса **убрана**.
