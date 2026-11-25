<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\AccountRepository;
use App\Repositories\CsvImportRepository;
use App\Repositories\OperationRepository;
use App\Support\CsvValueParser;
use PDO;

final class CsvImportService
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly PDO $db,
        private readonly AccountRepository $accounts,
        private readonly OperationRepository $operations,
        private readonly CsvImportRepository $imports,
        private readonly OperationService $operationService,
    ) {
    }

    public function recentForUser(int $userId): array
    {
        return $this->imports->recentForUser($userId);
    }

    public function previewForUser(int $userId, mixed $batchId): array
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una importación válida.');
        }
        $batch = $this->imports->batchForUser((int) $id, $userId);
        if (!$batch) {
            throw new DomainException('La importación no existe o no tienes acceso.');
        }

        return ['batch' => $batch, 'rows' => $this->imports->rowsForBatch((int) $id)];
    }

    public function createPreview(int $userId, array $input, mixed $file): int
    {
        $accountId = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1) {
            throw new DomainException('Selecciona una cuenta activa para importar.');
        }
        $account = null;
        foreach ($this->accounts->listForUser($userId, true) as $candidate) {
            if ((int) $candidate['id'] === (int) $accountId) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null) {
            throw new DomainException('No tienes acceso a esa cuenta o está archivada.');
        }

        if (!is_array($file)) {
            throw new DomainException('Selecciona un archivo CSV válido y vuelve a intentarlo.');
        }
        $temporaryPath = $file['tmp_name'] ?? null;
        $filename = $file['name'] ?? null;
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($temporaryPath)
            || !is_uploaded_file($temporaryPath)
            || !is_string($filename)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv'
        ) {
            throw new DomainException('Selecciona un archivo CSV válido y vuelve a intentarlo.');
        }
        $fileSize = filesize($temporaryPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > self::MAX_FILE_BYTES) {
            throw new DomainException('El archivo CSV debe pesar entre 1 byte y 2 MB.');
        }

        $delimiter = $input['delimiter'] ?? ',';
        if (!is_string($delimiter) || !in_array($delimiter, [',', ';', "\t"], true)) {
            throw new DomainException('Selecciona un separador CSV válido.');
        }
        $handle = fopen($temporaryPath, 'rb');
        if ($handle === false) {
            throw new DomainException('No fue posible leer el archivo CSV.');
        }
        try {
            $rows = $this->parseRows($handle, $delimiter, $input);
        } finally {
            fclose($handle);
        }
        if (!$rows) {
            throw new DomainException('El archivo no contiene movimientos para importar.');
        }

        $fingerprints = array_column($rows, 'import_fingerprint');
        $existing = $this->imports->existingFingerprints($userId, $fingerprints);
        $seen = [];
        foreach ($rows as &$row) {
            $duplicate = isset($existing[$row['import_fingerprint']]) || isset($seen[$row['import_fingerprint']]);
            $row['is_duplicate'] = $duplicate;
            $seen[$row['import_fingerprint']] = true;
        }
        unset($row);

        $this->db->beginTransaction();
        try {
            $batchId = $this->imports->createBatch($userId, (int) $accountId, $rows);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $batchId;
    }

    public function confirm(int $userId, mixed $batchId): array
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una importación válida.');
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $batch = $this->imports->batchForUser((int) $id, $userId, true);
            if (!$batch || $batch['status'] !== 'preview') {
                throw new DomainException('La importación no existe, ya fue procesada o no tienes acceso.');
            }
            $rows = $this->imports->rowsForBatch((int) $id);
            $operationIds = $this->operationService->createCsvRowsWithinTransaction(
                $userId,
                (int) $batch['account_id'],
                (int) $id,
                $rows
            );
            $this->imports->attachOperations((int) $id, $operationIds);
            $this->imports->completeBatch((int) $id);
            $this->db->commit();

            return [
                'processed' => count($operationIds),
                'duplicates' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => (int) $row['is_duplicate'] === 1
                )),
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function cancel(int $userId, mixed $batchId): void
    {
        $id = filter_var($batchId, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new DomainException('Selecciona una importación válida.');
        }
        $this->db->beginTransaction();
        try {
            $batch = $this->imports->batchForUser((int) $id, $userId, true);
            if (!$batch || $batch['status'] !== 'preview') {
                throw new DomainException('La importación no existe, ya fue procesada o no tienes acceso.');
            }
            $this->imports->cancelBatch((int) $id, $userId);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function parseRows($handle, string $delimiter, array $input): array
    {
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($headers) || count($headers) < 3) {
            throw new DomainException('La primera fila debe contener los encabezados de las columnas CSV.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $header = is_string($header) ? trim($header) : '';
            if ($header === '') {
                throw new DomainException('Los encabezados CSV no pueden estar vacíos.');
            }
            if (!mb_check_encoding($header, 'UTF-8')) {
                throw new DomainException('El archivo CSV debe estar codificado en UTF-8.');
            }
            $normalized = mb_strtolower($header);
            if (isset($normalizedHeaders[$normalized])) {
                throw new DomainException('El archivo contiene encabezados repetidos.');
            }
            $normalizedHeaders[$normalized] = $index;
        }

        $dateIndex = $this->columnIndex($input['date_column'] ?? null, $normalizedHeaders, 'fecha');
        $amountIndex = $this->columnIndex($input['amount_column'] ?? null, $normalizedHeaders, 'valor');
        $descriptionIndex = $this->columnIndex($input['description_column'] ?? null, $normalizedHeaders, 'descripción');
        $categoryColumn = trim((string) ($input['category_column'] ?? ''));
        $categoryIndex = $categoryColumn === ''
            ? null
            : $this->columnIndex($categoryColumn, $normalizedHeaders, 'categoría');
        if (count(array_unique(array_filter([$dateIndex, $amountIndex, $descriptionIndex, $categoryIndex], static fn ($index) => $index !== null))) !== count(array_filter([$dateIndex, $amountIndex, $descriptionIndex, $categoryIndex], static fn ($index) => $index !== null))) {
            throw new DomainException('Cada dato debe asignarse a una columna diferente.');
        }

        $defaultCategoryId = null;
        if ($categoryIndex === null) {
            $defaultCategoryId = filter_var($input['default_category_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$defaultCategoryId || $defaultCategoryId < 1) {
                throw new DomainException('Selecciona una categoría predeterminada o asigna una columna de categoría.');
            }
        }
        $categoryByName = [];
        $categoryById = [];
        foreach ($this->operations->categories() as $category) {
            $categoryByName[mb_strtolower(trim($category['name']))] = $category;
            $categoryById[(int) $category['id']] = $category;
        }
        if ($defaultCategoryId !== null && !isset($categoryById[(int) $defaultCategoryId])) {
            throw new DomainException('Selecciona una categoría predeterminada válida.');
        }

        $parsed = [];
        $lineNumber = 1;
        while (($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $lineNumber++;
            if ($lineNumber > 65535) {
                throw new DomainException('El archivo CSV contiene demasiadas líneas para procesarse de forma segura.');
            }
            if ($fields === [null] || $fields === []) {
                continue;
            }
            $hasContent = false;
            foreach ($fields as $field) {
                if (trim((string) $field) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) {
                continue;
            }
            if (count($parsed) >= self::MAX_ROWS) {
                throw new DomainException('El archivo supera el máximo de 1.000 movimientos por importación.');
            }
            if (count($fields) !== count($headers)) {
                throw new DomainException('La fila ' . $lineNumber . ' no coincide con la cantidad de columnas del encabezado.');
            }

            try {
                $date = CsvValueParser::normalizeDate($fields[$dateIndex] ?? '');
            } catch (DomainException $exception) {
                throw new DomainException('La fecha de la fila ' . $lineNumber . ' no es válida o está en el futuro.', 0, $exception);
            }
            try {
                $amount = CsvValueParser::normalizeAmount($fields[$amountIndex] ?? '');
            } catch (DomainException $exception) {
                throw new DomainException('El importe de la fila ' . $lineNumber . ' debe ser positivo y tener hasta dos decimales.', 0, $exception);
            }
            $description = trim((string) ($fields[$descriptionIndex] ?? ''));
            if (!mb_check_encoding($description, 'UTF-8')) {
                throw new DomainException('La fila ' . $lineNumber . ' no está codificada en UTF-8.');
            }
            if ($description === '' || mb_strlen($description) > 255) {
                throw new DomainException('La descripción de la fila ' . $lineNumber . ' debe tener entre 1 y 255 caracteres.');
            }
            if ($categoryIndex !== null && !mb_check_encoding((string) ($fields[$categoryIndex] ?? ''), 'UTF-8')) {
                throw new DomainException('La categoría de la fila ' . $lineNumber . ' no está codificada en UTF-8.');
            }
            $categoryName = $categoryIndex === null
                ? null
                : mb_strtolower(trim((string) ($fields[$categoryIndex] ?? '')));
            $category = $categoryIndex === null
                ? $categoryById[(int) $defaultCategoryId]
                : ($categoryByName[$categoryName] ?? null);
            if (!$category) {
                throw new DomainException('La categoría de la fila ' . $lineNumber . ' no coincide con una categoría activa de la aplicación.');
            }

            $fingerprint = hash('sha256', json_encode([
                'account' => (int) ($input['account_id'] ?? 0),
                'type' => $category['kind'],
                'category' => (int) $category['id'],
                'date' => $date,
                'amount' => $amount,
                'description' => $description,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $parsed[] = [
                'line_number' => $lineNumber,
                'operation_type' => $category['kind'],
                'category_id' => (int) $category['id'],
                'effective_date' => $date,
                'amount' => $amount,
                'description' => $description,
                'import_fingerprint' => $fingerprint,
                'is_duplicate' => false,
            ];
        }

        return $parsed;
    }

    private function columnIndex(mixed $column, array $headers, string $label): int
    {
        if (!is_string($column) || trim($column) === '') {
            throw new DomainException('Indica el encabezado de la columna de ' . $label . '.');
        }
        $key = mb_strtolower(trim($column));
        if (!array_key_exists($key, $headers)) {
            throw new DomainException('No se encontró el encabezado "' . trim($column) . '" en el archivo CSV.');
        }

        return (int) $headers[$key];
    }

}
