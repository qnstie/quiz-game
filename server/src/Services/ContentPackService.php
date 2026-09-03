<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Repo\MediaRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\Id;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class ContentPackService
{
    public const FORMAT = 'family-quiz-content';
    public const VERSION = 1;

    public function __construct(
        private QuizzesRepo $quizzes,
        private OptionsRepo $options,
        private MediaRepo $media,
        private SanitizerService $sanitizer,
        private ConfigBag $config,
    ) {}

    /**
     * @param list<string> $quizIds
     * @return array{path: string, filename: string}
     */
    public function exportZip(string $projectId, array $quizIds): array
    {
        $quizIds = array_values(array_unique(array_filter(array_map('strval', $quizIds))));
        if ($quizIds === []) {
            throw new InvalidArgumentException('Select at least one quiz');
        }

        $exported = [];
        $mediaFiles = [];
        foreach ($quizIds as $quizId) {
            $quiz = $this->quizzes->find($projectId, $quizId);
            if (!$quiz) {
                throw new InvalidArgumentException('Quiz not found: ' . $quizId);
            }
            $opts = $this->options->listForQuiz($projectId, $quizId);
            $htmlBlob = implode("\n", [
                (string) $quiz['description_html'],
                (string) $quiz['explanation_html'],
                ...array_map(static fn ($o) => (string) ($o['label_html'] ?? '') . "\n" . (string) ($o['feedback_html'] ?? ''), $opts),
            ]);
            foreach (self::extractMediaBasenames($htmlBlob) as $basename) {
                $abs = $this->resolveMediaFile($projectId, $basename);
                if ($abs !== null) {
                    $mediaFiles[$basename] = $abs;
                }
            }
            $exported[] = $this->quizToSpec($quiz, $opts);
        }

        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => Id::now(),
            'quizzes' => $exported,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'fqpack');
        if ($tmp === false) {
            throw new RuntimeException('TEMP_FAILED');
        }
        $zipPath = $tmp . '.zip';
        rename($tmp, $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP_FAILED');
        }
        $zip->addFromString(
            'family-quiz-content.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
        foreach ($mediaFiles as $basename => $abs) {
            $zip->addFile($abs, 'media/' . $basename);
        }
        $zip->close();

        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', $exported[0]['title'] ?? 'quiz') ?: 'quiz';
        $slug = trim($slug, '-') ?: 'quiz';
        $n = count($exported);
        $filename = $n === 1
            ? 'quiz-' . strtolower($slug) . '.zip'
            : 'quizzes-' . $n . '.zip';

        return ['path' => $zipPath, 'filename' => $filename];
    }

    /**
     * @return array{created: int, quizzes: list<array>}
     */
    public function importJson(string $projectId, mixed $decoded, ?string $uploadedBy = null): array
    {
        $specs = $this->parseQuizList($decoded);
        unset($uploadedBy);
        return $this->insertSpecs($projectId, $specs, []);
    }

    /**
     * @return array{created: int, quizzes: list<array>, mediaCopied: int}
     */
    public function importZip(string $projectId, string $zipPath, ?string $uploadedBy = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Could not open ZIP file');
        }

        $jsonRaw = null;
        $mediaEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameFromIndex($i));
            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
                continue;
            }
            $base = basename($name);
            if ($base === 'family-quiz-content.json' || $base === 'quizzes.json') {
                $jsonRaw = $zip->getFromIndex($i);
            } elseif (preg_match('#(^|/)media/([^/]+)$#', $name, $m) && preg_match('/^[a-f0-9\-]+\.[a-z0-9]+$/i', $m[2])) {
                $mediaEntries[$m[2]] = $i;
            } elseif ($jsonRaw === null && str_ends_with(strtolower($base), '.json')) {
                $jsonRaw = $zip->getFromIndex($i);
            }
        }

        if (!is_string($jsonRaw) || trim($jsonRaw) === '') {
            $zip->close();
            throw new InvalidArgumentException('ZIP is missing family-quiz-content.json');
        }
        $decoded = json_decode($jsonRaw, true);
        if (!is_array($decoded)) {
            $zip->close();
            throw new InvalidArgumentException('Invalid JSON inside ZIP');
        }
        $specs = $this->parseQuizList($decoded);

        $urlMap = [];
        $copied = 0;
        $dir = Connections::projectDir($projectId);
        foreach ($mediaEntries as $basename => $index) {
            $data = $zip->getFromIndex($index);
            if ($data === false || $data === '') {
                continue;
            }
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $id = Id::uuid();
            $storedRel = 'uploads/' . $id . '.' . $ext;
            $dest = $dir . '/' . $storedRel;
            if (file_put_contents($dest, $data) === false) {
                $zip->close();
                throw new RuntimeException('UPLOAD_FAILED');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($dest) ?: 'application/octet-stream';
            $width = null;
            $height = null;
            if (str_starts_with($mime, 'image/')) {
                $info = @getimagesize($dest);
                if (is_array($info)) {
                    $width = (int) $info[0];
                    $height = (int) $info[1];
                }
            }
            $this->media->create($projectId, [
                'id' => $id,
                'filename' => $basename,
                'stored_path' => $storedRel,
                'mime' => $mime,
                'bytes' => filesize($dest) ?: strlen($data),
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $uploadedBy,
                'created_at' => Id::now(),
            ]);
            $url = $this->config->webPath('/media/' . $projectId . '/' . $id . '.' . $ext);
            $urlMap[$basename] = $url;
            $copied++;
        }
        $zip->close();

        $inserted = $this->insertSpecs($projectId, $specs, $urlMap);
        $inserted['mediaCopied'] = $copied;
        return $inserted;
    }

    /**
     * @param list<array> $specs
     * @param array<string, string> $urlMap
     * @return array{created: int, quizzes: list<array>}
     */
    private function insertSpecs(string $projectId, array $specs, array $urlMap): array
    {
        $created = [];
        foreach ($specs as $spec) {
            if ($urlMap !== []) {
                $spec = $this->rewriteSpecMedia($spec, $urlMap);
            }
            $title = trim((string) ($spec['title'] ?? ''));
            if ($title === '') {
                $title = 'Untitled quiz';
            }
            $quiz = $this->quizzes->create($projectId, $title);
            $this->quizzes->update($projectId, $quiz['id'], [
                'description_html' => $this->sanitizer->clean((string) ($spec['description_html'] ?? '')),
                'explanation_html' => $this->sanitizer->clean((string) ($spec['explanation_html'] ?? '')),
                'points' => max(1, (int) ($spec['points'] ?? 1)),
                'shuffle_options' => !empty($spec['shuffle_options']) || !array_key_exists('shuffle_options', $spec) ? 1 : 0,
            ]);
            $opts = $this->normalizeOptions($spec['options'] ?? []);
            $clean = [];
            foreach ($opts as $o) {
                $clean[] = [
                    'label_html' => $this->sanitizer->clean((string) $o['label_html']),
                    'feedback_html' => $this->sanitizer->clean((string) $o['feedback_html']),
                    'is_correct' => !empty($o['is_correct']),
                ];
            }
            $this->options->replaceAll($projectId, $quiz['id'], $clean);
            $row = $this->quizzes->find($projectId, $quiz['id']);
            if ($row) {
                $row['options'] = $this->options->listForQuiz($projectId, $quiz['id']);
                $created[] = $row;
            }
        }
        return ['created' => count($created), 'quizzes' => $created];
    }

    /**
     * @param array<string, mixed> $quiz
     * @param list<array<string, mixed>> $opts
     * @return array<string, mixed>
     */
    private function quizToSpec(array $quiz, array $opts): array
    {
        return [
            'title' => (string) $quiz['title'],
            'description_html' => self::toPackHtml((string) $quiz['description_html']),
            'explanation_html' => self::toPackHtml((string) $quiz['explanation_html']),
            'points' => (int) ($quiz['points'] ?? 1),
            'shuffle_options' => (int) ($quiz['shuffle_options'] ?? 1) === 1,
            'options' => array_map(static function (array $o): array {
                return [
                    'label_html' => self::toPackHtml((string) ($o['label_html'] ?? '')),
                    'feedback_html' => self::toPackHtml((string) ($o['feedback_html'] ?? '')),
                    'is_correct' => !empty($o['is_correct']),
                ];
            }, $opts),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, string> $urlMap basename => public url
     * @return array<string, mixed>
     */
    private function rewriteSpecMedia(array $spec, array $urlMap): array
    {
        $spec['description_html'] = self::fromPackHtml((string) ($spec['description_html'] ?? ''), $urlMap);
        $spec['explanation_html'] = self::fromPackHtml((string) ($spec['explanation_html'] ?? ''), $urlMap);
        if (isset($spec['options']) && is_array($spec['options'])) {
            foreach ($spec['options'] as $i => $o) {
                if (!is_array($o)) {
                    continue;
                }
                $spec['options'][$i]['label_html'] = self::fromPackHtml((string) ($o['label_html'] ?? ''), $urlMap);
                $spec['options'][$i]['feedback_html'] = self::fromPackHtml((string) ($o['feedback_html'] ?? ''), $urlMap);
            }
        }
        return $spec;
    }

    public static function toPackHtml(string $html): string
    {
        return (string) preg_replace(
            '#(?:https?:)?(?://[^/]+)?(?:/[^"\']*)?/media/[a-f0-9\-]+/([a-f0-9\-]+\.[a-z0-9]+)#i',
            'media/$1',
            $html
        );
    }

    /** @param array<string, string> $urlMap */
    public static function fromPackHtml(string $html, array $urlMap): string
    {
        return (string) preg_replace_callback(
            '#(?:src|href)=(["\'])(?:(?:https?:)?(?://[^/]+)?(?:/[^"\']*)?/media/[a-f0-9\-]+/)?(?:media/)?([a-f0-9\-]+\.[a-z0-9]+)\1#i',
            static function (array $m) use ($urlMap): string {
                $basename = $m[2];
                $attr = str_starts_with(strtolower($m[0]), 'href') ? 'href' : 'src';
                $url = $urlMap[$basename] ?? $m[0];
                if (isset($urlMap[$basename])) {
                    return $attr . '=' . $m[1] . $urlMap[$basename] . $m[1];
                }
                return $m[0];
            },
            $html
        );
    }

    /** @return list<string> */
    public static function extractMediaBasenames(string $html): array
    {
        preg_match_all('#/media/[a-f0-9\-]+/([a-f0-9\-]+\.[a-z0-9]+)#i', $html, $a);
        preg_match_all('#(?:^|[\'"=(])media/([a-f0-9\-]+\.[a-z0-9]+)#i', $html, $b);
        $names = array_unique(array_merge($a[1] ?? [], $b[1] ?? []));
        return array_values($names);
    }

    private function resolveMediaFile(string $projectId, string $basename): ?string
    {
        if (!preg_match('/^[a-f0-9\-]+\.[a-z0-9]+$/i', $basename)) {
            return null;
        }
        $dir = Connections::projectDir($projectId);
        foreach ($this->media->list($projectId) as $row) {
            if (basename((string) $row['stored_path']) === $basename) {
                $abs = $dir . '/' . $row['stored_path'];
                if (is_file($abs)) {
                    return $abs;
                }
            }
        }
        foreach (['uploads/' . $basename, 'media/' . $basename] as $rel) {
            $abs = $dir . '/' . $rel;
            if (is_file($abs)) {
                return $abs;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public function parseQuizList(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JSON must be an object or array');
        }
        if (array_is_list($decoded)) {
            $list = $decoded;
        } elseif (isset($decoded['quizzes']) && is_array($decoded['quizzes'])) {
            $list = $decoded['quizzes'];
        } elseif (isset($decoded['title']) || isset($decoded['options'])) {
            $list = [$decoded];
        } else {
            throw new InvalidArgumentException('JSON must include a quizzes array (or a single quiz object)');
        }
        if ($list === []) {
            throw new InvalidArgumentException('No quizzes in file');
        }
        $out = [];
        foreach ($list as $i => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Quiz #' . ((int) $i + 1) . ' is not an object');
            }
            $item['options'] = $this->normalizeOptions($item['options'] ?? []);
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param mixed $options
     * @return list<array{label_html: string, feedback_html: string, is_correct: bool}>
     */
    public function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            throw new InvalidArgumentException('options must be an array of 4 items');
        }
        $rows = [];
        foreach ($options as $o) {
            if (!is_array($o)) {
                throw new InvalidArgumentException('Each option must be an object');
            }
            $rows[] = [
                'label_html' => (string) ($o['label_html'] ?? $o['label'] ?? ''),
                'feedback_html' => (string) ($o['feedback_html'] ?? $o['feedback'] ?? ''),
                'is_correct' => !empty($o['is_correct']),
            ];
        }
        if (count($rows) > 4) {
            throw new InvalidArgumentException('Each quiz must have at most 4 options');
        }
        while (count($rows) < 4) {
            $rows[] = ['label_html' => '', 'feedback_html' => '', 'is_correct' => false];
        }
        $correct = 0;
        foreach ($rows as $r) {
            if ($r['is_correct']) {
                $correct++;
            }
        }
        if ($correct === 0) {
            $rows[0]['is_correct'] = true;
        } elseif ($correct !== 1) {
            throw new InvalidArgumentException('Exactly one option must be is_correct');
        }
        return $rows;
    }
}
