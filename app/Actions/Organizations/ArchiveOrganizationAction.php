<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\ConflictException;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes an organization out of service without destroying anything.
 *
 * The reversible counterpart to deleting one. Deleting is a hard delete that takes every member
 * with it and cannot be undone, so an organization that has merely stopped for now was until here
 * answered with the one operation that cannot be taken back. Archiving keeps every row where it is
 * and only stops the organization being used.
 *
 * Ending the members' sessions is deleting their credentials, which is how a session ends
 * everywhere here: nothing is signed or cached, so the guard stops resolving a caller as soon as
 * the row is gone rather than at some expiry. There are two kinds to delete — the API token a
 * client carries and the cookie session a browser holds — and {@see AuthenticationTokenService}
 * owns both so that no caller has to remember them separately. Their sign-in links go too: a link
 * is a credential as well, and one already sitting in an inbox would otherwise open a session on
 * an organization that is supposed to be closed.
 */
final readonly class ArchiveOrganizationAction
{
    public function __construct(private AuthenticationTokenService $tokens) {}

    public function execute(User $actor, Organization $organization): Organization
    {
        // Said out loud for the same reason deleting says it: every platform administrator belongs
        // to the platform's own organization, so archiving it would lock every operator out of the
        // panel that would have to undo it.
        if ($organization->is_platform) {
            throw new ConflictException(OrganizationMessages::PLATFORM_CANNOT_BE_ARCHIVED);
        }

        if ($actor->organization_id === $organization->getKey()) {
            throw new ConflictException(OrganizationMessages::CANNOT_ARCHIVE_OWN_ORGANIZATION);
        }

        return DB::transaction(function () use ($organization): Organization {
            $now = Carbon::now();

            // Claimed with a conditional UPDATE rather than by reading the column and then writing
            // it: two operators archiving at once would otherwise both find it open, both proceed,
            // and the second would move the timestamp on an organization that was already closed.
            $archived = Organization::query()
                ->whereKey($organization->getKey())
                ->whereNull('archived_at')
                ->update(['archived_at' => $now]);

            if ($archived === 0) {
                throw new ConflictException(OrganizationMessages::ALREADY_ARCHIVED);
            }

            // Saved through the model as well, now that the transition is this request's, so that
            // `StampsAuditor` records who closed it. The statement above is what makes the change
            // exclusive; this is what makes it attributable, and an operation this consequential
            // should not be the one change with no author.
            $organization->archived_at = $now;
            $organization->save();

            // Soft-deleted members are left out, and that is not an oversight: deleting a user
            // already deletes their tokens and their links, so there is nothing of theirs left to
            // withdraw.
            $memberIds = User::query()
                ->where('organization_id', $organization->getKey())
                ->get(['id'])
                ->map(static fn (User $member): string => (string) $member->getKey())
                ->values()
                ->all();

            LoginToken::query()->whereIn('user_id', $memberIds)->delete();

            $this->tokens->revokeAllFor($memberIds);

            return $organization;
        });
    }
}
