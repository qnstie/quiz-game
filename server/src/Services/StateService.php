<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Support\Id;
use InvalidArgumentException;

final class StateService
{
    private const STATES = ['SETUP', 'ACTIVE', 'CLOSED', 'REVEALED'];

    public function __construct(
        private ProjectsRepo $projects,
        private ScoringService $scoring,
    ) {}

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

        if ($newState === 'ACTIVE' && in_array($from, ['CLOSED', 'REVEALED'], true)) {
            $fields['results_stale'] = 1;
        }

        return $this->projects->update($projectId, $fields);
    }

    public function assertContentMutable(?array $project): void
    {
        if (!$project || $project['state'] !== 'SETUP') {
            $state = $project['state'] ?? 'UNKNOWN';
            throw new LockedException($state);
        }
    }
}
