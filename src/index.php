<?php
declare(strict_types=1);

final class BitrixClient
{
    private string $baseUrl;
    private ?array $dealFieldsCache = null;
    private ?array $leadFieldsCache = null;
    private ?array $companyFieldsCache = null;

    /* ===== UF коды: Сделка ===== */
    private const UF_WORK_TYPES_DEAL        = 'UF_CRM_1755625105555'; // enum multiple: Виды работ
    private const UF_PLACE_TYPE_DEAL        = 'UF_CRM_1755625205341'; // enum: Тип помещения
    private const UF_AREA_DEAL              = 'UF_CRM_1755625259983'; // double: Площадь
    // private const UF_LEGAL_SUMMARY_DEAL     = 'UF_CRM_1755634129009'; // string: Юр. сводка
    private const UF_PAYMENT_PURPOSE_DEAL   = 'UF_CRM_1755635797930'; // string: Назначение платежа
    private const UF_HAS_DESIGN_DEAL        = 'UF_CRM_1756305031525'; // boolean: Наличие ДП/планировки
    private const UF_PAID_DEAL              = 'UF_CRM_1756297456738'; // boolean: Оплачено (если пригодится)

    /* ===== UF коды: Лид ===== */
    private const UF_WORK_TYPES_LEAD        = 'UF_CRM_1756233341';    // enum multiple: Виды работ
    private const UF_PLACE_TYPE_LEAD        = 'UF_CRM_1756234012227'; // enum: Тип помещения
    private const UF_AREA_LEAD              = 'UF_CRM_1756234049657'; // double: Площадь
    private const UF_LEGAL_SUMMARY_LEAD     = 'UF_CRM_1756237988929'; // string: Данные Юр Лица (сводка)
    private const UF_PAYMENT_PURPOSE_LEAD   = 'UF_CRM_1756297123947'; // string: Назначение платежа
    private const UF_INN_LEAD               = 'UF_CRM_1756297158897'; // string: ИНН
    private const UF_LEGAL_ADDRESS_LEAD     = 'UF_CRM_1756304797452'; // address: Юридический адрес (строка ок)
    private const UF_HAS_DESIGN_LEAD        = 'UF_CRM_1756305002991'; // boolean: Наличие ДП/планировки

    public function __construct(string $baseWebhookUrl)
    {
        $this->baseUrl = rtrim($baseWebhookUrl, '/') . '/';
    }

    /* ==================== ПУБЛИЧКА ==================== */

    /** Сделка: антидубли контакта/компании + создание сделки */
    public function createDefaultDealSafe(string $title, array $subject, array $data = [], array $extra = []): array
    {
        try {
            $id = $this->createDefaultDeal($title, $subject, $data, $extra);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Лид: реюз открытого лида по телефону/почте (повторный) либо создание нового */
    public function createDefaultLeadSafe(string $title, array $subject, array $data = [], array $extra = []): array
    {
        try {
            $id = $this->createDefaultLead($title, $subject, $data, $extra);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /* ==================== ВНУТРЯНКА: СДЕЛКА ==================== */

    private function createDefaultDeal(string $title, array $subject, array $data, array $extra): int
    {
        [$contactId, $companyId, $phoneNorm, $emailNorm, $inn, $titleCmp] = $this->resolveSubjectEntities($subject);

        // UF для сделки
        $uf = [];
        if (array_key_exists('work_types', $data)) $uf[self::UF_WORK_TYPES_DEAL] = $data['work_types'];
        if (array_key_exists('place_type', $data)) $uf[self::UF_PLACE_TYPE_DEAL] = $data['place_type'];
        if (array_key_exists('area', $data))       $uf[self::UF_AREA_DEAL]       = $data['area'];
        if (!empty($data['payment_purpose']))      $uf[self::UF_PAYMENT_PURPOSE_DEAL] = (string)$data['payment_purpose'];
        if (array_key_exists('has_design', $data)) $uf[self::UF_HAS_DESIGN_DEAL]      = $this->toBool($data['has_design']);
        if (array_key_exists('paid', $data))       $uf[self::UF_PAID_DEAL]            = $this->toBool($data['paid']); // опционально

        // автоподхват любых UF_CRM_* из $data
        foreach ($data as $k => $v) {
            if (is_string($k) && strncmp($k, 'UF_CRM_', 7) === 0 && !array_key_exists($k, $uf)) $uf[$k] = $v;
        }

        // Юр.сводка (если юрлицо/есть подсказки)
        // $hasCompanyHints = ($companyId !== null) || !empty($titleCmp) || !empty($inn) || (($subject['type'] ?? '') === 'company');
        // if ($hasCompanyHints) {
        //     $addrStr = trim((string)($subject['legal_address'] ?? $data['legal_address'] ?? ''));
        //     $uf[self::UF_LEGAL_SUMMARY_DEAL] = $this->formatCompanySummary(
        //         (string)($titleCmp ?? ''), (string)($inn ?? ''), (string)($phoneNorm ?? ''), (string)($emailNorm ?? ''), $addrStr
        //     );
        // }

        // Сумма/валюта
        $amount   = $this->parseFloatOrNull($data['amount'] ?? null);
        $currency = strtoupper((string)($data['currency'] ?? 'RUB'));

        $fields = array_merge([
            'TITLE'  => $title,
            'OPENED' => 'Y',
        ], $extra);

        if ($contactId)        $fields['CONTACT_ID'] = $contactId; // для сделок ок
        if ($companyId)        $fields['COMPANY_ID'] = $companyId;
        if ($amount !== null)  $fields['OPPORTUNITY'] = $amount;
        if ($currency !== '')  $fields['CURRENCY_ID'] = $currency;

        // маппинг UF
        $fields = array_merge($fields, $this->resolveUserFields($uf, $this->getDealFields()));

        $resp = $this->call('crm.deal.add', ['fields' => $fields]);
        if (!isset($resp['result']) || !is_int($resp['result'])) {
            $msg = $resp['error_description'] ?? 'Unknown Bitrix response';
            throw new RuntimeException("Failed to create deal: {$msg}");
        }
        return $resp['result'];
    }

    /* ==================== ВНУТРЯНКА: ЛИД ==================== */

        private function createDefaultLead(string $title, array $subject, array $data, array $extra): int
    {
        // НИКАКИХ контактов/компаний — только поля лида
        $phoneNorm = isset($subject['phone']) ? $this->normalizePhone((string)$subject['phone']) : null;
        $emailNorm = isset($subject['email']) ? $this->normalizeEmail((string)$subject['email']) : null;
        $inn       = isset($subject['inn'])   ? trim((string)$subject['inn'])                     : null;
        $titleCmp  = isset($subject['title']) ? trim((string)$subject['title'])                   : null;

        // Собираем UF для лида
        $uf = [];
        if (array_key_exists('work_types', $data)) $uf[self::UF_WORK_TYPES_LEAD]      = $data['work_types'];
        if (array_key_exists('place_type', $data)) $uf[self::UF_PLACE_TYPE_LEAD]      = $data['place_type'];
        if (array_key_exists('area', $data))       $uf[self::UF_AREA_LEAD]            = $data['area'];
        if (!empty($data['payment_purpose']))      $uf[self::UF_PAYMENT_PURPOSE_LEAD] = (string)$data['payment_purpose'];
        if (!empty($inn))                           $uf[self::UF_INN_LEAD]             = (string)$inn;
        if (!empty($subject['legal_address']) || !empty($data['legal_address'])) {
            $uf[self::UF_LEGAL_ADDRESS_LEAD] = (string)($subject['legal_address'] ?? $data['legal_address']);
        }
        if (array_key_exists('has_design', $data))  $uf[self::UF_HAS_DESIGN_LEAD]     = $this->toBool($data['has_design']);

        // автоподхват любых UF_CRM_* из $data
        foreach ($data as $k => $v) {
            if (is_string($k) && strncmp($k, 'UF_CRM_', 7) === 0 && !array_key_exists($k, $uf)) {
                $uf[$k] = $v;
            }
        }

        // Человекочитаемая юр.сводка — только в UF лида
        $hasCompanyHints = !empty($titleCmp) || !empty($inn) || (($subject['type'] ?? '') === 'company');
        if ($hasCompanyHints) {
            $addrStr = trim((string)($subject['legal_address'] ?? $data['legal_address'] ?? ''));
            $uf[self::UF_LEGAL_SUMMARY_LEAD] = $this->formatCompanySummary(
                (string)($titleCmp ?? ''), (string)($inn ?? ''), (string)($phoneNorm ?? ''), (string)($emailNorm ?? ''), $addrStr
            );
        }

        // Сумма/валюта (у лида есть эти поля)
        $amount   = $this->parseFloatOrNull($data['amount'] ?? null);
        $currency = strtoupper((string)($data['currency'] ?? 'RUB'));

        // Базовые поля лида — БЕЗ CONTACT_IDS/COMPANY_ID
        $fields = [
            'TITLE'     => $title,
            'OPENED'    => 'Y',
            'STATUS_ID' => $extra['STATUS_ID'] ?? 'NEW', // дефолтная стадия
        ];
        foreach ($extra as $k => $v) {
            if ($k !== 'STATUS_ID') $fields[$k] = $v;
        }

        if (!empty($subject['name'])) $fields['NAME'] = trim((string)$subject['name']);
        if (!empty($titleCmp))        $fields['COMPANY_TITLE'] = $titleCmp;

        // Мультиполя — всегда при создании (мы не делаем update существующего лида)
        if ($phoneNorm) $fields['PHONE'] = [['TYPE_ID' => 'PHONE', 'VALUE' => $phoneNorm, 'VALUE_TYPE' => 'WORK']];
        if ($emailNorm) $fields['EMAIL'] = [['TYPE_ID' => 'EMAIL', 'VALUE' => $emailNorm, 'VALUE_TYPE' => 'WORK']];

        if ($amount !== null) $fields['OPPORTUNITY'] = $amount;
        if ($currency !== '') $fields['CURRENCY_ID'] = $currency;

        // Приведение UF по метаданным лида (enum/double/boolean/address/…)
        $fields = array_merge($fields, $this->resolveUserFields($uf, $this->getLeadFields()));

        // Всегда создаём НОВЫЙ лид — «первичный»
        $resp = $this->call('crm.lead.add', ['fields' => $fields]);
        if (!isset($resp['result']) || !is_int($resp['result'])) {
            $msg = $resp['error_description'] ?? 'Unknown Bitrix response';
            throw new RuntimeException("Failed to create lead: {$msg}");
        }
        return $resp['result'];
    }


    /** вернуть открытый лид (STATUS_SEMANTIC_ID !== 'F') по телефону или почте */
    private function findReusableLeadId(?string $phone, ?string $email): ?int
    {
        if ($phone) {
            $r = $this->call('crm.lead.list', [
                'filter' => ['PHONE' => $phone],
                'select' => ['ID','STATUS_SEMANTIC_ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            foreach ((array)($r['result'] ?? []) as $row) {
                if (($row['STATUS_SEMANTIC_ID'] ?? '') !== 'F') return (int)$row['ID'];
            }
        }
        if ($email) {
            $r = $this->call('crm.lead.list', [
                'filter' => ['EMAIL' => $email],
                'select' => ['ID','STATUS_SEMANTIC_ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            foreach ((array)($r['result'] ?? []) as $row) {
                if (($row['STATUS_SEMANTIC_ID'] ?? '') !== 'F') return (int)$row['ID'];
            }
        }
        return null;
    }

    /* ==================== АНТИДУБЛИ СУБЪЕКТОВ ==================== */

    private function resolveSubjectEntities(array $subject): array
    {
        $contactId = !empty($subject['contact_id']) ? (int)$subject['contact_id'] : null;
        $companyId = !empty($subject['company_id']) ? (int)$subject['company_id'] : null;

        $phoneNorm = isset($subject['phone']) ? $this->normalizePhone((string)$subject['phone']) : null;
        $emailNorm = isset($subject['email']) ? $this->normalizeEmail((string)$subject['email']) : null;
        $inn       = isset($subject['inn'])   ? trim((string)$subject['inn'])                     : null;
        $titleCmp  = isset($subject['title']) ? trim((string)$subject['title'])                   : null;

        $hasCompanyHints = !empty($titleCmp) || !empty($inn) || (($subject['type'] ?? '') === 'company');
        if (!$companyId && $hasCompanyHints) {
            $companyId = $this->findCompanyIdByInnOrComm($inn, $phoneNorm, $emailNorm, $titleCmp);
            if (!$companyId && $titleCmp) {
                $companyId = $this->createCompany([
                    'title' => $titleCmp,
                    'phone' => $phoneNorm,
                    'email' => $emailNorm,
                    'inn'   => $inn,
                ]);
            }
        }

        if (!$contactId && !empty($subject['name'])) {
            $contactId = $this->findContactIdByPhoneEmail($phoneNorm, $emailNorm);
            if (!$contactId) {
                $name = trim((string)$subject['name']);
                if ($name === '') throw new InvalidArgumentException('Contact name is required');
                $contactId = $this->createContact($name, $phoneNorm, $emailNorm);
            }
        }

        return [$contactId, $companyId, $phoneNorm, $emailNorm, $inn, $titleCmp];
    }

    private function findContactIdByPhoneEmail(?string $phone, ?string $email): ?int
    {
        if ($phone) {
            $r = $this->call('crm.contact.list', [
                'filter' => ['PHONE' => $phone],
                'select' => ['ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            $id = $r['result'][0]['ID'] ?? null;
            if ($id) return (int)$id;
        }
        if ($email) {
            $r = $this->call('crm.contact.list', [
                'filter' => ['EMAIL' => $email],
                'select' => ['ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            $id = $r['result'][0]['ID'] ?? null;
            if ($id) return (int)$id;
        }
        return null;
    }

    private function findCompanyIdByInnOrComm(?string $inn, ?string $phone, ?string $email, ?string $title): ?int
    {
        if ($inn) {
            $innUF = $this->guessCompanyInnUF();
            if ($innUF) {
                $r = $this->call('crm.company.list', [
                    'filter' => [$innUF => $inn],
                    'select' => ['ID'],
                    'order'  => ['ID' => 'ASC'],
                ]);
                $id = $r['result'][0]['ID'] ?? null;
                if ($id) return (int)$id;
            }
        }
        if ($phone) {
            $r = $this->call('crm.company.list', [
                'filter' => ['PHONE' => $phone],
                'select' => ['ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            $id = $r['result'][0]['ID'] ?? null;
            if ($id) return (int)$id;
        }
        if ($email) {
            $r = $this->call('crm.company.list', [
                'filter' => ['EMAIL' => $email],
                'select' => ['ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            $id = $r['result'][0]['ID'] ?? null;
            if ($id) return (int)$id;
        }
        if ($title) {
            $r = $this->call('crm.company.list', [
                'filter' => ['TITLE' => $title],
                'select' => ['ID'],
                'order'  => ['ID' => 'ASC'],
            ]);
            $id = $r['result'][0]['ID'] ?? null;
            if ($id) return (int)$id;
        }
        return null;
    }

    private function createContact(string $name, ?string $phone = null, ?string $email = null): int
    {
        $fields = ['NAME' => $name, 'OPENED' => 'Y'];
        if ($phone && ($phone = $this->normalizePhone($phone))) $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        if ($email) $fields['EMAIL'] = [['VALUE' => $this->normalizeEmail($email), 'VALUE_TYPE' => 'WORK']];

        $resp = $this->call('crm.contact.add', ['fields' => $fields]);
        if (!isset($resp['result']) || !is_int($resp['result'])) {
            $msg = $resp['error_description'] ?? 'Unknown Bitrix response';
            throw new RuntimeException("Failed to create contact: {$msg}");
        }
        return $resp['result'];
    }

    private function createCompany(array $c): int
    {
        $title = trim((string)($c['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Company title is required');

        $fields = ['TITLE' => $title, 'OPENED' => 'Y'];
        if (!empty($c['phone']) && ($ph = $this->normalizePhone((string)$c['phone']))) {
            $fields['PHONE'] = [['VALUE' => $ph, 'VALUE_TYPE' => 'WORK']];
        }
        if (!empty($c['email'])) {
            $fields['EMAIL'] = [['VALUE' => $this->normalizeEmail((string)$c['email']), 'VALUE_TYPE' => 'WORK']];
        }
        if (!empty($c['inn'])) {
            $innUF = $this->guessCompanyInnUF();
            if ($innUF) $fields[$innUF] = (string)$c['inn'];
        }

        $resp = $this->call('crm.company.add', ['fields' => $fields]);
        if (!isset($resp['result']) || !is_int($resp['result'])) {
            $msg = $resp['error_description'] ?? 'Unknown Bitrix response';
            throw new RuntimeException("Failed to create company: {$msg}");
        }
        return $resp['result'];
    }

    /* ==================== МЕТАДАННЫЕ/МАППИНГ ==================== */

    private function getDealFields(): array
    {
        if ($this->dealFieldsCache !== null) return $this->dealFieldsCache;
        $resp = $this->call('crm.deal.fields', []);
        if (!isset($resp['result']) || !is_array($resp['result'])) throw new RuntimeException('Failed to fetch deal field metadata');
        return $this->dealFieldsCache = $resp['result'];
    }

    private function getLeadFields(): array
    {
        if ($this->leadFieldsCache !== null) return $this->leadFieldsCache;
        $resp = $this->call('crm.lead.fields', []);
        if (!isset($resp['result']) || !is_array($resp['result'])) throw new RuntimeException('Failed to fetch lead field metadata');
        return $this->leadFieldsCache = $resp['result'];
    }

    private function getCompanyFields(): array
    {
        if ($this->companyFieldsCache !== null) return $this->companyFieldsCache;
        $resp = $this->call('crm.company.fields', []);
        if (!isset($resp['result']) || !is_array($resp['result'])) throw new RuntimeException('Failed to fetch company field metadata');
        return $this->companyFieldsCache = $resp['result'];
    }

    /** искать UF компании для ИНН по лейблам (ИНН/INN) */
    private function guessCompanyInnUF(): ?string
    {
        $meta = $this->getCompanyFields();
        foreach ($meta as $code => $m) {
            if (strncmp($code, 'UF_CRM_', 7) !== 0) continue;
            if (($m['type'] ?? 'string') !== 'string') continue;
            $labels = $this->lower(trim(
                (string)($m['title'] ?? '') . ' ' .
                (string)($m['formLabel'] ?? '') . ' ' .
                (string)($m['listLabel'] ?? '') . ' ' .
                (string)($m['filterLabel'] ?? '')
            ));
            if (str_contains($labels, 'инн') || str_contains($labels, 'inn')) return $code;
        }
        return null;
    }

    private function resolveUserFields(array $ufData, array $meta): array
    {
        $out = [];
        foreach ($ufData as $code => $val) {
            if ($val === null) continue;
            $m = $meta[$code] ?? null;
            if ($m === null) { $out[$code] = $val; continue; }

            $type = $m['type'] ?? 'string';
            $isMultiple = !empty($m['isMultiple']);

            switch ($type) {
                case 'enumeration': $out[$code] = $this->resolveEnum($val, $m, $isMultiple); break;
                case 'double':      $out[$code] = $this->resolveDouble($val, $isMultiple);   break;
                case 'integer':     $out[$code] = $this->resolveInteger($val, $isMultiple);  break;
                case 'boolean':     $out[$code] = $this->resolveBoolean($val, $isMultiple);  break;
                case 'address':     $out[$code] = $this->resolveAddress($val, $isMultiple);  break;
                default:
                    $out[$code] = $isMultiple ? (array)$val : (is_array($val) ? reset($val) : $val);
            }
        }
        return $out;
    }

    private function resolveEnum(mixed $input, array $fieldMeta, bool $isMultiple): mixed
    {
        $items = $fieldMeta['items'] ?? [];

        if (empty($items)) {
            if ($isMultiple) {
                $arr = is_array($input) ? $input : [$input];
                $ids = [];
                foreach ($arr as $v) if (is_numeric($v)) $ids[] = (int)$v;
                if ($ids === []) throw new InvalidArgumentException("Enum: items missing, provide numeric IDs");
                return $ids;
            }
            if (is_numeric($input)) return (int)$input;
            throw new InvalidArgumentException("Enum: items missing, provide numeric ID");
        }

        $byId = $byValue = [];
        foreach ($items as $it) {
            $id  = (string)($it['ID'] ?? '');
            $val = (string)($it['VALUE'] ?? '');
            if ($id !== '')  $byId[$id] = $id;
            if ($val !== '') $byValue[$this->lower(trim($val))] = $id;
        }

        $mapOne = function ($v) use ($byId, $byValue) {
            $s = is_scalar($v) ? trim((string)$v) : '';
            if ($s === '') return null;
            if (ctype_digit($s) && isset($byId[$s])) return (int)$s;
            $key = $this->lower($s);
            if (isset($byValue[$key])) return (int)$byValue[$key];
            throw new InvalidArgumentException("Enum value '{$s}' not found");
        };

        if ($isMultiple) {
            $arr = is_array($input) ? $input : [$input];
            $ids = [];
            foreach ($arr as $v) { $id = $mapOne($v); if ($id !== null) $ids[] = $id; }
            return $ids;
        }
        $v  = is_array($input) ? reset($input) : $input;
        $id = $mapOne($v);
        if ($id === null) throw new InvalidArgumentException("Enum expects non-empty value");
        return $id;
    }

    private function resolveDouble(mixed $input, bool $isMultiple): mixed
    {
        $toFloat = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) return (float)$v;
            $s = str_replace(',', '.', (string)$v);
            if (is_numeric($s)) return (float)$s;
            throw new InvalidArgumentException("Invalid double: {$v}");
        };
        if ($isMultiple) {
            $arr = is_array($input) ? $input : [$input];
            $out = [];
            foreach ($arr as $v) { $f = $toFloat($v); if ($f !== null) $out[] = $f; }
            return $out;
        }
        $f = $toFloat(is_array($input) ? reset($input) : $input);
        return $f ?? 0.0;
    }

    private function resolveInteger(mixed $input, bool $isMultiple): mixed
    {
        $toInt = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) return (int)$v;
            throw new InvalidArgumentException("Invalid integer: {$v}");
        };
        if ($isMultiple) {
            $arr = is_array($input) ? $input : [$input];
            $out = [];
            foreach ($arr as $v) { $i = $toInt($v); if ($i !== null) $out[] = $i; }
            return $out;
        }
        $i = $toInt(is_array($input) ? reset($input) : $input);
        return $i ?? 0;
    }

    private function resolveBoolean(mixed $input, bool $isMultiple): mixed
    {
        $toBit = function ($v): string {
            if (is_array($v)) $v = reset($v);
            if (is_bool($v)) return $v ? '1' : '0';
            $s = $this->lower(trim((string)$v));
            if ($s === '' || $s === '0' || $s === 'n' || $s === 'no' || $s === 'false' || $s === 'off') return '0';
            return '1'; // всё остальное трактуем как true: '1','y','yes','true','on'
        };
        if ($isMultiple) {
            $arr = is_array($input) ? $input : [$input];
            return array_map($toBit, $arr);
        }
        return $toBit($input);
    }

    private function resolveAddress(mixed $input, bool $isMultiple): mixed
    {
        $toStr = function ($v): string {
            if (is_array($v)) $v = implode(', ', array_filter(array_map('strval', $v)));
            return trim((string)$v);
        };
        if ($isMultiple) {
            $arr = is_array($input) ? $input : [$input];
            return array_map($toStr, $arr);
        }
        return $toStr($input);
    }

    /* ==================== ТРАНСПОРТ ==================== */

    private function call(string $method, array $params): array
    {
        $url = $this->baseUrl . $method;
        $payload = http_build_query($params);

        $attempts = 3;
        $delayMs = 300;

        while ($attempts-- > 0) {
            $ch = curl_init($url);
            if ($ch === false) throw new RuntimeException('curl_init failed');

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $raw   = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0 || $raw === false || $raw === '') {
                if ($attempts > 0) { usleep($delayMs * 1000); $delayMs *= 2; continue; }
                $msg = $errno !== 0 ? "cURL error ({$errno}): {$err}" : "Empty response from Bitrix (HTTP {$code})";
                throw new RuntimeException($msg);
            }

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                if ($attempts > 0) { usleep($delayMs * 1000); $delayMs *= 2; continue; }
                throw new RuntimeException("Invalid JSON from Bitrix: {$raw}");
            }

            if (isset($json['error'])) {
                $errCode = (string)($json['error'] ?? '');
                $transient = ($code === 429)
                    || str_contains($errCode, 'TOO_MANY_REQUESTS')
                    || str_contains($errCode, 'METHOD_QUOTA_EXCEEDED')
                    || str_contains($errCode, 'TEMPORARY_UNAVAILABLE');
                if ($attempts > 0 && $transient) { usleep($delayMs * 1000); $delayMs *= 2; continue; }
                $desc = $json['error_description'] ?? $json['error'];
                throw new RuntimeException("Bitrix error: {$desc}");
            }

            return $json;
        }

        throw new RuntimeException("Bitrix call failed");
    }

    /* ==================== УТИЛЬ ==================== */

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return $phone;
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) return '+7' . substr($digits, 1);
        if ($digits[0] !== '0') return '+' . $digits;
        return $phone;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function parseFloatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace(',', '.', (string)$v);
        return is_numeric($s) ? (float)$s : null;
    }

    private function formatCompanySummary(string $title, string $inn, string $phone, string $email, string $addr): string
    {
        $lines = [];
        if ($title !== '') $lines[] = "Компания: {$title}";
        if ($inn   !== '') $lines[] = "ИНН: {$inn}";
        if ($phone !== '') $lines[] = "Телефон: {$phone}";
        if ($email !== '') $lines[] = "Email: {$email}";
        if ($addr  !== '') $lines[] = "Юр. адрес: {$addr}";
        return implode("\n", $lines);
    }

    private function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private function toBool(mixed $v): string
    {
        if (is_bool($v)) return $v ? '1' : '0';
        $s = $this->lower(trim((string)$v));
        if ($s === '' || $s === '0' || $s === 'n' || $s === 'no' || $s === 'false' || $s === 'off') return '0';
        return '1'; // всё остальное считаем true
    }
}


/* ======== ПРИМЕРЫ ======== */
$bx = new BitrixClient('https://b24-7d59hz.bitrix24.ru/rest/1/lcupf1tphfngxpnt/');

// Лид: калькулятор (физик)
// $lead = $bx->createDefaultLeadSafe(
//   'Заявка (калькулятор)',
//   ['name'=>'Иван','phone'=>'+7 (999) 9293-7081','email'=>'ittttttt@gnail.com'],
//   [
//     'work_types' => ['Вентиляция и кондиционирование','Электрика'],
//     'place_type' => 'Квартира',
//     'area'       => '85,2',
//     'amount'     => 120000,
//     'has_design' => true,
//   ],
//   ['ASSIGNED_BY_ID'=>1,'SOURCE_ID'=>'WEB']
// );
// var_dump($lead);

// Лид: юрлицо (счёт)
$bx->createDefaultLeadSafe(
  'Запрос счёта',
  [
    'type'=>'company','title'=>'ООО "Рога"',
    'inn'=>'7701234267','phone'=>'+7 495 0000010','email'=>'acc1@roga.ru',
    'legal_address'=>'123456, Россия, Москва, ул. Пушкина, д. 1',
  ],
  [
    'work_types'      => ['Проектирование','Отопление'],
    'place_type'      => 'Офис',
    'area'            => 560,
    'amount'          => 350000,
    'payment_purpose' => 'Оплата по договору №42',
    'has_design'      => false,
  ],
  ['ASSIGNED_BY_ID'=>1,'SOURCE_ID'=>'WEB']
);

// // Сделка: оплата на сайте (физик)
// $bx->createDefaultDealSafe(
//   'Оплата заказа #123',
//   ['name'=>'Иван','phone'=>'+7 999 111-22-33','email'=>'ivan@ex.com'],
//   [
//     'work_types' => [44, 52],   // ID тоже норм
//     'place_type' => 'Квартира',
//     'area'       => 85.2,
//     'amount'     => 120000,
//     'has_design' => true,
//   ],
//   ['CATEGORY_ID'=>0,'STAGE_ID'=>'NEW','ASSIGNED_BY_ID'=>1]
// );