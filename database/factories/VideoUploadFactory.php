<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoUpload;
use App\Support\Videos\VideoFormat;
use App\Support\Videos\VideoStorage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @extends Factory<VideoUpload> */
final class VideoUploadFactory extends Factory
{
    protected $model = VideoUpload::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $id = (string) Str::orderedUuid();

        return [
            'id' => $id,
            'issued_to' => User::factory(),
            'storage_key' => VideoStorage::keyFor($id),
            'declared_byte_count' => 2048,
            'expires_at' => Carbon::now()->addHour(),
        ];
    }

    /** Issued to somebody in particular. */
    public function issuedTo(User $user): self
    {
        return $this->state(fn (): array => ['issued_to' => $user->getKey()]);
    }

    /** Written, looked at and found to be a video. */
    public function verified(): self
    {
        return $this->state(fn (): array => [
            'content_type' => VideoFormat::MP4,
            'byte_count' => 2048,
            'verified_at' => Carbon::now(),
        ]);
    }

    /** Past the end of its link. */
    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => Carbon::now()->subMinute()]);
    }

    /** Already on a video. */
    public function claimed(): self
    {
        return $this->state(fn (): array => ['claimed_at' => Carbon::now()]);
    }
}
