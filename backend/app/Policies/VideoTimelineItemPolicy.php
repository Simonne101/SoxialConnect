<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoTimelineItem;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Élément de timeline : accès du propriétaire du projet vidéo uniquement (refus en 404, protection IDOR). */
class VideoTimelineItemPolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoTimelineItem $item): Response
    {
        return $this->readsProject($user, $item->track?->project);
    }

    public function update(User $user, VideoTimelineItem $item): Response
    {
        return $this->writesProject($user, $item->track?->project);
    }

    public function delete(User $user, VideoTimelineItem $item): Response
    {
        return $this->writesProject($user, $item->track?->project);
    }
}
