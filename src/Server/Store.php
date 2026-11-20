<?php
declare(strict_types=1);

namespace Parliament\Server;

/**
 * Live games on disk: data/games/ID.json, one file a game, written under an
 * exclusive lock so two browsers (and the AI) never interleave a move.
 */
final class Store
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public static function newId(): string
    {
        return substr(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), 0, 10);
    }

    public function path(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{6,40}$/', $id)) {
            throw new \InvalidArgumentException('bad game id');
        }
        return "{$this->dir}/$id.json";
    }

    public function read(string $id): ?array
    {
        $f = $this->path($id);
        if (!is_file($f)) {
            return null;
        }
        $h = fopen($f, 'r');
        flock($h, LOCK_SH);
        $s = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);
        return json_decode((string)$s, true);
    }

    /** Read-modify-write under one exclusive lock. $fn gets the record and returns the new one (or null to leave it). */
    public function update(string $id, callable $fn, bool $create = false): ?array
    {
        $f = $this->path($id);
        if (!$create && !is_file($f)) {
            throw new ApiError('no such game', 404); // never leave an empty file for an unknown id
        }
        $h = fopen($f, 'c+');
        flock($h, LOCK_EX);
        $s = stream_get_contents($h);
        $rec = $s ? json_decode($s, true) : null;
        $new = $fn($rec);
        if ($new !== null) {
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($new, JSON_UNESCAPED_SLASHES));
            fflush($h);
        }
        flock($h, LOCK_UN);
        fclose($h);
        return $new ?? $rec;
    }

    public function all(int $limit = 40): array
    {
        $files = glob("{$this->dir}/*.json") ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach (array_slice($files, 0, $limit) as $f) {
            $r = json_decode((string)file_get_contents($f), true);
            if ($r) {
                $out[] = ['id' => $r['id'], 'mode' => $r['mode'], 'players' => $r['players'], 'status' => $r['game']['status'],
                    'result' => $r['game']['result'], 'plies' => count($r['game']['log']), 'updated' => filemtime($f),
                    'version' => $r['version']];
            }
        }
        return $out;
    }
}
