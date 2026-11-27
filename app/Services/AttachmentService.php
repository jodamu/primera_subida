<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use PDO;

final class AttachmentService
{
    private const MAX_BYTES = 5242880;
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly PDO $db, private readonly string $storageDir)
    {
    }

    public function listForOperation(int $userId, int $operationId): array
    {
        $this->assertAccess($userId, $operationId);
        $statement = $this->db->prepare(
            'SELECT id, original_name, mime_type, size_bytes, created_at
             FROM operation_attachments WHERE operation_id = :id AND deleted_at IS NULL ORDER BY id'
        );
        $statement->execute(['id' => $operationId]);

        return $statement->fetchAll();
    }

    public function upload(int $userId, int $operationId, mixed $file): void
    {
        $this->assertAccess($userId, $operationId);
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'], $file['name'])) {
            throw new DomainException('Selecciona un archivo para adjuntar.');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new DomainException('El archivo supera el tamaño permitido (5 MB).');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('No pudimos recibir el archivo. Inténtalo de nuevo.');
        }
        $size = (int) $file['size'];
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new DomainException('El archivo debe pesar entre 1 byte y 5 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new DomainException('Solo se permiten archivos PDF, JPG, PNG o WebP.');
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM operation_attachments WHERE operation_id = :id AND deleted_at IS NULL'
        );
        $count->execute(['id' => $operationId]);
        if ((int) $count->fetchColumn() >= 5) {
            throw new DomainException('Cada movimiento admite hasta 5 adjuntos.');
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new DomainException('No pudimos preparar el almacenamiento de adjuntos.');
        }
        $storedName = bin2hex(random_bytes(20));
        $target = $this->storageDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new DomainException('No pudimos guardar el archivo.');
        }
        chmod($target, 0640);

        $originalName = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', basename((string) $file['name'])) ?? 'adjunto';
        $originalName = mb_substr($originalName, 0, 200);
        if ($originalName === '') {
            $originalName = 'adjunto.' . self::MIME_EXTENSIONS[$mime];
        }

        try {
            $this->db->prepare(
                'INSERT INTO operation_attachments (operation_id, stored_name, original_name, mime_type, size_bytes, sha256, uploaded_by)
                 VALUES (:operation_id, :stored_name, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by)'
            )->execute([
                'operation_id' => $operationId,
                'stored_name' => $storedName,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => hash_file('sha256', $target),
                'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    /** @return array{path: string, name: string, mime: string, size: int} */
    public function forDownload(int $userId, int $attachmentId): array
    {
        $row = $this->find($attachmentId);
        $this->assertAccess($userId, (int) $row['operation_id']);
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $row['stored_name'];
        if (!is_file($path)) {
            throw new DomainException('El archivo ya no está disponible.');
        }

        return ['path' => $path, 'name' => $row['original_name'], 'mime' => $row['mime_type'], 'size' => (int) $row['size_bytes']];
    }

    public function delete(int $userId, int $attachmentId): int
    {
        $row = $this->find($attachmentId);
        $this->assertAccess($userId, (int) $row['operation_id']);
        $this->db->prepare('UPDATE operation_attachments SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL')
            ->execute(['id' => $attachmentId]);

        return (int) $row['operation_id'];
    }

    private function find(int $attachmentId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, operation_id, stored_name, original_name, mime_type, size_bytes
             FROM operation_attachments WHERE id = :id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $attachmentId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new DomainException('El adjunto no existe.');
        }

        return $row;
    }

    private function assertAccess(int $userId, int $operationId): void
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM operations o
             WHERE o.id = :operation_id AND (
                (o.scope = "personal" AND o.owner_user_id = :user_a)
                OR (o.scope = "household" AND EXISTS (
                    SELECT 1 FROM household_members hm
                    WHERE hm.household_id = o.household_id AND hm.user_id = :user_b AND hm.status = "active"))
             )'
        );
        $statement->execute(['operation_id' => $operationId, 'user_a' => $userId, 'user_b' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('No tienes acceso a este movimiento.');
        }
    }
}
