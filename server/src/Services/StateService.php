<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Support\Id;
use InvalidArgumentException;

final class StateService
{
    private const STATES = ['SETUP', 'TEST', 'ACTIVE', 'CLOSED', 'REVEALED'];

    public function __construct(
        private ProjectsRepo $projects,
        private ScoringService $scoring,
    ) {}

    /** Content CRUD allowed in SETUP (private) and TEST (live for testers). */
    public static function isContentMutable(?string $state): bool
    {
        return in_array($state ?? '', ['SETUP', 'TEST'], true);
    }

    /** Participants may submit / change answers. */
    public static function isAnswerable(?string $state): bool
    {
        return in_array($state ?? '', ['ACTIVE', 'TEST'], true);
    }

    /** Project is visible on the participant bootstrap (not SETUP-only drafting). */
    public static function isParticipantVisible(?string $state): bool
    {
        return ($state ?? '') !== '' && ($state ?? '') !== 'SETUP';
    }

    public function transition(string $projectId, string $newState): array
    {
        if (!in_array($newState, self::STATES, true)) {
            throw new InvalidArgumentException('Invalid state');
        }
        $project = $this->projects->find($projectId);
        if (!$project) {
            throw new InvalidArgumentException('Project not found');
        }

        $fields = ['state' => $newState];
        $from = $project['state'];

        if ($newState === 'CLOSED') {
            $this->scoring->computeResults($projectId);
            $fields['closed_at'] = Id::now();
            $fields['results_stale'] = 0;
        }

        if ($newState === 'REVEALED') {
            $fields['revealed_at'] = Id::now();
            if ($from !== 'CLOSED' && (int) $project['results_stale'] === 1) {
                $this->scoring->computeResults($projectId);
                $fields['results_stale'] = 0;
            }
        }

        if (in_array($newState, ['ACTIVE', 'TEST'], true) && in_array($from, ['CLOSED', 'REVEALED'], true)) {
            $fields['results_stale'] = 1;
        }

        $project = $this->projects->update($projectId, $fields);

        // Make the project visible to participants as soon as it goes live or into test.
        if (in_array($newState, ['ACTIVE', 'TEST'], true)) {
            $this->projects->setSetting('active_project_id', $projectId);
            $this->projects->setSetting('public_project_id', $projectId);
        }

        return $project;
    }

    public function assertContentMutable(?array $project): void
    {
        $state = $project['state'] ?? 'UNKNOWN';
        if (!$project || !self::isContentMutable($state)) {
            throw new LockedException($state);
        }
    }
}
