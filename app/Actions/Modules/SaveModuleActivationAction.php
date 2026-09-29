<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use App\Support\Modules\ActivationDetails;
use App\Support\Modules\ContactDetails;
use App\Support\Modules\LinkDetails;
use App\Support\Modules\OrderedRows;
use Illuminate\Support\Facades\DB;

/**
 * What an organization adds to its own copy of a module: its videos, its links, its contacts.
 *
 * The one write on a module that is an organization's rather than the platform's, so the question
 * is `OrganizationAccess`'s and not `ModuleAccess`'s: whoever manages that organization may, which
 * is its own administrators and the platform. Nothing here reaches the module itself or any other
 * organization's copy — every list is written under this activation, and a key from anywhere else
 * is a new row (rule 22).
 */
final readonly class SaveModuleActivationAction
{
    public function __construct(private SaveModuleVideosAction $videos) {}

    public function execute(User $actor, ModuleActivation $activation, ActivationDetails $details): ModuleActivation
    {
        OrganizationAccess::ensureCanManage($actor, $activation->organization_id);

        DB::transaction(function () use ($actor, $activation, $details): void {
            if ($details->videos !== null) {
                $this->videos->execute($actor, $activation, $details->videos);
            }

            if ($details->links !== null) {
                OrderedRows::write(
                    $activation->links(),
                    $details->links,
                    static fn (LinkDetails $link): ?string => $link->id,
                    static function (ModuleLink $row, LinkDetails $link): void {
                        $row->title = trim($link->title);
                        $row->url = trim($link->url);
                    },
                );
            }

            if ($details->contacts !== null) {
                OrderedRows::write(
                    $activation->contacts(),
                    $details->contacts,
                    static fn (ContactDetails $contact): ?string => $contact->id,
                    static function (ModuleContact $row, ContactDetails $contact): void {
                        $row->name = trim($contact->name);
                        $row->email = $contact->email;
                        $row->phone = $contact->phone;
                        $row->reason = $contact->reason;
                        $row->availability = $contact->availability;
                    },
                );
            }
        });

        return $activation;
    }
}
