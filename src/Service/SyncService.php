<?php

namespace Caxy\HarvestForecast\Service;

use Caxy\ForecastApi\ForecastClient;
use Caxy\HarvestForecast\Exception\NotFoundInForecastException;
use GuzzleHttp\Exception\ServerException;
use Harvest\HarvestApi;
use Harvest\Model\Range;
use Symfony\Component\Debug\Debug;

class SyncService
{
    /**
     * @var HarvestApi
     */
    protected $harvest;
    /**
     * @var ForecastClient
     */
    protected $forecast;
    /**
     * @var array
     */
    protected $harvestProjects = array();
    /**
     * @var array
     */
    protected $forecastProjects = array();
    /**
     * @var array
     */
    protected $linkedProjects = array();
    /**
     * @var array
     */
    protected $harvestUsers = array();
    /**
     * @var array
     */
    protected $forecastUsers = array();
    /**
     * @var array
     */
    protected $linkedUsers = array();
    /**
     * @var array
     */
    protected $assignments = array();

    private $notFoundErrors = array();

    /**
     * SyncService constructor.
     *
     * @param HarvestApi     $harvest
     * @param ForecastClient $forecast
     */
    public function __construct(HarvestApi $harvest, ForecastClient $forecast)
    {
        $this->harvest  = $harvest;
        $this->forecast = $forecast;
    }

    public function sync(Range $range = null)
    {
        // Default range to this month.
        if (null === $range) {
            $range = Range::thisMonth();
        }

        // Load all projects and the links between them.
        $this->loadHarvestProjects();
        $this->loadForecastProjects();
        $this->linkProjects();

        // Get all users.
        $this->loadHarvestUsers();
        $this->loadForecastUsers();
        $this->linkUsers();

        $this->loadAssignments($range);

        // Loop through users and sync user entries to forecast.
        foreach ($this->harvestUsers as $user) {
            try {
                $this->syncUserEntries($user, $range);
            } catch (NotFoundInForecastException $e) {
                $this->addNotFoundError($e->getMessage());
            }
        }

        return $this->notFoundErrors;
    }

    public function syncUserEntries($user, Range $range)
    {
        // Check if user exists in forecast.
        if (!array_key_exists($user->id, $this->linkedUsers)) {
            throw new NotFoundInForecastException(
                sprintf(
                    'Harvest user %s %s (%s) does not exist in forecast.',
                    $user->{'first-name'},
                    $user->{'last-name'},
                    $user->id
                )
            );
        }

        $forecastUserId = $this->linkedUsers[$user->id];

        $userEntries = $this->harvest->getUserEntries($user->id, $range)->data;

        echo sprintf(
            "\nHarvest user %s %s has *%s* entries in this date range.",
            $user->{'first-name'},
            $user->{'last-name'},
            \count($userEntries)
        );

        $entriesByProject = $this->groupEntriesByProjectAndDate($userEntries);

        foreach ($entriesByProject as $harvestProjectId => $dateEntries) {
            $harvestProject = $this->harvestProjects[$harvestProjectId];
            // Check if project exists in forecast.
            if (!array_key_exists($harvestProjectId, $this->linkedProjects)) {
                $this->addNotFoundError(
                    sprintf(
                        'Harvest Project %s (%s) not found in Forecast projects.',
                        $harvestProject->name,
                        $harvestProjectId
                    )
                );
                continue;
            }

            $forecastProjectId = $this->linkedProjects[$harvestProjectId];

            $lastEntryDate = max(array_keys($dateEntries));
            $endDate = new \DateTime($lastEntryDate);
            $curDate = new \DateTime($range->from());

            $lastDate = null;
            $lastDateAssignment = null;

            while ($curDate <= $endDate) {
                // Get the date as string.
                $date = $curDate->format('Y-m-d');
                $this->syncUserEntriesOnDate($date, $dateEntries, $forecastUserId, $forecastProjectId, $lastDateAssignment, $user);

                // Increment the date for next loop.
                $curDate->modify('+1 day');

            }
        }
    }

    private function addNotFoundError($message)
    {
        if (!in_array($message, $this->notFoundErrors, true)) {
            $this->notFoundErrors[] = $message;
        }
    }

    protected function syncUserEntriesOnDate($date, $dateEntries, $userId, $projectId, array &$lastDateAssignment = null, $harvestUser)
    {
        // Forecast configured to deny weekends.
        if ($this->isWeekend($date)) {
            return false;
        }

        // Check if harvest entries exist on this day.
        $entries = array();
        if (array_key_exists($date, $dateEntries)) {
            $entries = $dateEntries[$date];
        }

        // Check if assignment already exists in forecast.
        $assignment = $this->findAssignmentOnDate($userId, $projectId, $date);

        // Nothing to do if no entries and no assignments on this day.
        if (empty($entries) && $assignment === null) {
            $lastDateAssignment = null;
            return false;
        }

        // Sum total hours for the date.
        $allocation = $this->calculateAllocationFromEntries($entries);
        $allocationHours = $allocation / 60 / 60;

        if ($allocationHours < 0 || $allocationHours > 24) {
            echo "\n[$userId][$projectId][$date] Allocation must be between 0 and 24 hours, but was: " . $allocationHours;
            $this->addNotFoundError("[$userId][$projectId][$date][" . $harvestUser->{'last-name'} . "] Allocation must be between 0 and 24 hours, but was: " . $allocationHours);
            return false;
        }

        if (null === $assignment) {
            if ($this->shouldExtendPrevAssignment($lastDateAssignment, $allocation)) {
                // Last date's allocation was the same, so let's just extend that.
                $lastDateAssignment['end_date'] = $date;
                $lastDateAssignment = $this->updateAssignment($lastDateAssignment);
                echo "\n[$userId][$projectId][$date] Extending the last assignment date.";
            } else {
                // Create forecast assignment for this time entry.
                $lastDateAssignment = $this->createAssignment($userId, $projectId, $date, $date, $allocation);
                echo "\n[$userId][$projectId][$date] Creating new assignment.";
            }

            return true;
        }

        // If no entries but there's a forecast assignment on this day, it should be split or deleted.
        if (empty($entries)) {
            // If the assignment is just today, then delete it.
            if ($this->isSingleDayAssignment($assignment)) {
                $this->deleteAssignment($assignment['id']);
                echo "\n[$userId][$projectId][$date] Deleting single-day assignment.";
            } else {
                $this->splitAssignment($assignment, $date);
                echo "\n[$userId][$projectId][$date] Splitting assignment to remove from this day.";
            }

            // Clear the last date.
            $lastDateAssignment = null;

            return true;
        }

        // If allocation matches, we continue to next entry.
        if ($assignment['allocation'] === $allocation) {
            if (
                $this->shouldExtendPrevAssignment($lastDateAssignment, $allocation) &&
                $lastDateAssignment['id'] !== $assignment['id']
            ) {
                // Delete this assignment and extend the previous.
                $this->deleteAssignment($assignment['id']);
                $lastDateAssignment['end_date'] = $assignment['end_date'];
                $lastDateAssignment = $this->updateAssignment($lastDateAssignment);
                echo "\n[$userId][$projectId][$date] Extending the last assignment date and replacing this one.";
            } else {
                $lastDateAssignment = $assignment;
            }
            return false;
        }

        // Update allocation or split.
        if ($this->isSingleDayAssignment($assignment)) {
            if ($this->shouldExtendPrevAssignment($lastDateAssignment, $allocation)) {
                // Delete this assignment and extend the previous.
                $this->deleteAssignment($assignment['id']);
                $lastDateAssignment['end_date'] = $assignment['end_date'];
                $lastDateAssignment = $this->updateAssignment($lastDateAssignment);
                echo "\n[$userId][$projectId][$date] Extending the last assignment date and replacing this one.";
            } else {
                // Update the assignment allocation.
                $assignment['allocation'] = $allocation;
                $lastDateAssignment = $this->updateAssignment($assignment);
                echo "\n[$userId][$projectId][$date] Updated allocation on assignment.";
            }
        } else {
            // Split the assignment, and a new assignment will be created below.
            $this->splitAssignment($assignment, $date);
            // Create forecast assignment for this time entry.
            $lastDateAssignment = $this->createAssignment($userId, $projectId, $date, $date, $allocation);

            echo "\n[$userId][$projectId][$date] Splitting assignment and creating new one for today.";
        }

        return true;
    }

    protected function shouldExtendPrevAssignment($prev, $allocation): bool
    {
        return null !== $prev && $prev['allocation'] === $allocation;
    }

    protected function calculateAllocationFromEntries(array $entries, $roundingFraction = 2)
    {
        if (empty($entries)) {
            return 0;
        }

        // Sum total hours for the date.
        $totalHours = 0;
        foreach ($entries as $entry) {
            $totalHours += $entry->hours;
        }

        // Rounding to nearest half-hour to keep things clean.
        $roundedHours = $this->roundToNearestFraction($totalHours, $roundingFraction);

        if (empty($roundedHours)) {
            // Round up if we're at 0.
            $roundedHours = 1 / $roundingFraction;
        }

        // Forecast requires seconds instead of hours.
        return (int)($roundedHours * 60 * 60);
    }

    protected function isSingleDayAssignment($assignment)
    {
        return $assignment['start_date'] === $assignment['end_date'];
    }

    protected function roundToNearestFraction($num, $fraction = 4)
    {
        return round($num * $fraction) / $fraction;
    }

    /**
     * @param string $date
     *
     * @return bool
     */
    protected function isWeekend($date): bool
    {
        return (date('N', strtotime($date)) >= 6);
    }

    protected function modifyDateString($date, $modify)
    {
        $dateTime = new \DateTime($date);
        do {
            $dateTime->modify($modify);
        } while ($this->isWeekend($dateTime->format('Y-m-d')));

        return $dateTime->format('Y-m-d');
    }

    protected function splitAssignment($assignment, $splitOn)
    {
        $start = $assignment['start_date'];
        $end = $assignment['end_date'];

        if ($start === $end) {
            throw new \InvalidArgumentException("Cannot split assignment that has same start and end dates.");
        }

        $dayBeforeSplit = $this->modifyDateString($splitOn, '-1 day');
        $dayAfterSplit = $this->modifyDateString($splitOn, '+1 day');
        $dayAfterStart = $this->modifyDateString($start, '+1 day');
        $dayBeforeEnd = $this->modifyDateString($end, '-1 day');

        $createNew = false;
        if ($start === $splitOn) {
            // Starts on this day, so we can just adjust start date.
            $assignment['start_date'] = $dayAfterStart;
        } elseif ($end === $splitOn) {
            // Ends on this day, so decrement the end date.
            $assignment['end_date'] = $dayBeforeEnd;
        } else {
            // Splitting: Use existing assignment for before and create new for after.
            $assignment['end_date'] = $dayBeforeSplit;
            $createNew = true;
        }
        // Update the assignment.
        $updated = $this->updateAssignment($assignment);
        // Create new assignment if splitting.
        if ($createNew) {
            $this->createAssignment(
                $assignment['person_id'],
                $assignment['project_id'],
                $dayAfterSplit,
                $end,
                $assignment['allocation']
            );
        }

        return $updated;
    }

    protected function createAssignment($userId, $projectId, $startDate, $endDate, $allocation)
    {
        $data = array(
            'assignment' => array(
                'allocation' => $allocation, // Harvest is in hours, forecast requires seconds.
                'end_date' => $endDate,
                'person_id' => (string) $userId,
                'project_id' => (string) $projectId,
                'repeated_assignment_set_id' => null,
                'start_date' => $startDate,
            ),
        );

        echo "\nCreate assignment";
        $response = $this->forecast->postAssignment($data);

        $assignment = $response['assignment'];
        $this->updateLocalAssignments($assignment);

        return $assignment;
    }

    protected function updateAssignment(array $assignment)
    {
        if (!array_key_exists('id', $assignment)) {
            throw new \InvalidArgumentException("ID missing from assignment object");
        }

        $data = array('assignment' => $assignment);

        echo "\nUpdateAssignment";
        $response = $this->forecast->putAssignment($assignment['id'], $data);

        $assignment = $response['assignment'];
        $this->updateLocalAssignments($assignment);

        return $assignment;
    }

    protected function deleteAssignment($id): bool
    {
        echo "\ndeleteAssignment: $id";
        $this->forecast->deleteAssignment($id);

        foreach ($this->assignments as $userId => $userAssignments) {
            if (array_key_exists($id, $userAssignments)) {
                unset($this->assignments[$userId][$id]);
                return true;
            }
        }

        return false;
    }

    protected function updateLocalAssignments(array $assignment)
    {
        if (!array_key_exists('id', $assignment)) {
            throw new \InvalidArgumentException("ID missing from assignment object");
        }

        $id = $assignment['id'];
        $personId = $assignment['person_id'];

        if (!array_key_exists($personId, $this->assignments)) {
            $this->assignments[$personId] = array();
        }

        $this->assignments[$personId][$id] = $assignment;
    }

    protected function findAssignmentOnDate($userId, $projectId, $date)
    {
        // Check if user has any assignments loaded.
        if (!array_key_exists($userId, $this->assignments)) {
            return null;
        }

        foreach ($this->assignments[$userId] as $assignment) {
            // Skip assignments not on this project.
            if ($assignment['project_id'] !== $projectId) {
                continue;
            }

            // Check if search date is within the assignment range.
            if ($date >= $assignment['start_date'] && $date <= $assignment['end_date']) {
                return $assignment;
            }
        }

        return null;
    }

    protected function loadHarvestProjects()
    {
        $projects = $this->harvest->getProjects()->data;

        foreach ($projects as $project) {
            if (!array_key_exists($project->id, $this->harvestProjects)) {
                $this->harvestProjects[$project->id] = $project;
            }
        }
    }

    protected function loadForecastProjects()
    {
        $projects = $this->forecast->getProjects();

        foreach ($projects as $project) {
            // if project is archived in forecast, skip it.
            if ($project['archived'] === true || $project['archived'] === 'true') {
                echo "\nIgnoring forecast project ".$project['name']." because it is archived.";
                continue;
            }

            if (!array_key_exists($project['id'], $this->forecastProjects)) {
                $this->forecastProjects[$project['id']] = $project;
            }
        }
    }

    protected function linkProjects()
    {
        foreach ($this->harvestProjects as $id => $project) {
            $nameMatch = null;
            foreach ($this->forecastProjects as $forecastId => $forecastProject) {
                if (!empty($forecastProject['harvest_id']) && $forecastProject['harvest_id'] === $id) {
                    $this->linkedProjects[$id] = $forecastId;
                    $nameMatch = null;
                    break;
                }
                if ($forecastProject['name'] === $project->name) {
                    $nameMatch = $forecastId;
                }
            }

            // Resort to matching by name if no id match.
            if ($nameMatch !== null) {
                $this->linkedProjects[$id] = $nameMatch;
            }
        }
    }

    protected function groupEntriesByProjectAndDate($entries)
    {
        $data = array();
        foreach ($entries as $entry) {
            $projectId = $entry->project_id;
            $date = $entry->spent_at;
            if (!array_key_exists($projectId, $data)) {
                $data[$projectId] = array();
            }
            if (!array_key_exists($date, $data[$projectId])) {
                $data[$projectId][$date] = array();
            }
            $data[$projectId][$date][] = $entry;
        }

        return $data;
    }

    /**
     * @return array|mixed
     */
    protected function loadHarvestUsers()
    {
        $users = $this->harvest->getUsers()->data;
        foreach ($users as $user) {
            if (!array_key_exists($user->id, $this->harvestUsers)) {
                $this->harvestUsers[$user->id] = $user;
            }
        }
    }

    protected function loadForecastUsers()
    {
        $users = $this->forecast->getPeople();
        foreach ($users as $user) {
            if (!$user['archived'] && !array_key_exists($user['id'], $this->forecastUsers)) {
                $this->forecastUsers[$user['id']] = $user;
            }
        }
    }

    protected function linkUsers()
    {
        foreach ($this->harvestUsers as $id => $harvestUser) {
            $nameMatch = null;
            foreach ($this->forecastUsers as $forecastId => $forecastUser) {
                if (!empty($forecastUser['harvest_user_id']) && $forecastUser['harvest_user_id'] === $id) {
                    $this->linkedUsers[$id] = $forecastId;
                    $nameMatch = null;
                    break;
                }
                if ($forecastUser['email'] === $harvestUser->email) {
                    $nameMatch = $forecastId;
                } elseif (
                    $forecastUser['first_name'] === $harvestUser->{'first-name'} &&
                    $forecastUser['last_name'] === $harvestUser->{'last-name'}
                ) {
                    $nameMatch = $forecastId;
                }
            }

            // Resort to matching by name if no id match.
            if ($nameMatch !== null) {
                $this->linkedUsers[$id] = $nameMatch;
            }
        }
    }

    protected function loadAssignments(Range $range)
    {
        $from = new \DateTime($range->from());
        $to = new \DateTime($range->to());

        $assignments = [];

        if ($from->diff($to)->days > 180) {
            $hasMore = true;
            $fromCursor = clone $from;
            while ($hasMore) {
                $newTo = clone $fromCursor;
                $newTo->modify('+180 days');

                $assignments = array_merge($assignments, $this->forecast->getAssignments($fromCursor->format('Ymd'), $newTo->format('Ymd')));

                $fromCursor = $newTo;

                if ($fromCursor >= $to) {
                    $hasMore = false;
                }
            }
        } else {
            $assignments = $this->forecast->getAssignments($range->from(), $range->to());
        }

        $this->assignments = $this->groupAssignmentsByUser($assignments);
    }

    /**
     * @param array $assignments
     *
     * @return array
     */
    protected function groupAssignmentsByUser(array $assignments): array
    {
        $data = array();
        foreach ($assignments as $assignment) {
            if (!array_key_exists($assignment['person_id'], $data)) {
                $data[$assignment['person_id']] = array();
            }

            $data[$assignment['person_id']][$assignment['id']] = $assignment;
        }

        return $data;
    }
}
