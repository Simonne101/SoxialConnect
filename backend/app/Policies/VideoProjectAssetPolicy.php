<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoProjectAsset;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Média rattaché à un projet vidéo : accès du propriétaire du projet vidéo uniquement (refus en 404, protection IDOR). */
class VideoProjectAssetPolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoProjectAsset $asset): Response
    {
        return $this->readsProject($user, $asset->project);
    }

    public function update(User $user, VideoProjectAsset $asset): Response
    {
        return $this->writesProject($user, $asset->project);
    }

    public function delete(User $user, VideoProjectAsset $asset): Response
    {
        return $this->writesProject($user, $asset->project);
    }
}
