<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Resources\ELearningShareResource;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\ShareELearning;
use App\Support\Access\ModuleAccess;
use App\Support\Links\QrCodeDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A course as each organization shares it, for the platform's share dialog (KOM-94) — what the
 * panel's "Delen" offers ({@see ShareELearning}), through the API.
 *
 * A share is not a row: it exists while the organization has a module showing the course, so
 * there is nothing to create or delete here. A course is shared by switching a module on.
 *
 * The platform's alone, as everything on an e-learning is. An organization administrator is
 * handed their own organization's links on the module page instead
 * ({@see ModuleController::eLearningQrCode()}), where the organization is whoever is reading.
 */
final readonly class ELearningShareController
{
    /** Returns every organization the course is shared with, each with its links. */
    public function index(Request $request, ELearning $eLearning): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanManageContent($actor);

        $activations = ModuleAccess::activationsSharing($eLearning)
            ->with(['organization', 'module'])
            ->get()
            ->sortBy(static fn (ModuleActivation $activation): string => mb_strtolower($activation->organization->name)."\0".mb_strtolower($activation->module->name))
            ->values();

        return response()->json([
            'items' => $activations
                ->map(static fn (ModuleActivation $activation): ELearningShareResource => new ELearningShareResource($activation->organization, $activation->module, $eLearning))
                ->values(),
        ]);
    }

    /** Returns the course as one organization shares it: its link and its QR code. */
    public function show(Request $request, ELearning $eLearning, Organization $organization, Module $module): ELearningShareResource
    {
        self::ensureShared($request, $eLearning, $organization, $module);

        return new ELearningShareResource($organization, $module, $eLearning);
    }

    /** Returns the QR code for the course as one organization shares it, as a PNG. */
    public function qrCode(Request $request, ELearning $eLearning, Organization $organization, Module $module): QrCodeDownload
    {
        self::ensureShared($request, $eLearning, $organization, $module);

        return new QrCodeDownload($organization, $module, $eLearning, QrCodeDownload::asksToDownload($request));
    }

    /** A link for an organization that does not share the course would lead to a 404, so neither is handed out. */
    private static function ensureShared(Request $request, ELearning $eLearning, Organization $organization, Module $module): void
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanManageContent($actor);

        if (! ModuleAccess::isSharedThrough($eLearning, $organization, $module)) {
            throw new NotFoundException('Deze e-learning wordt niet gedeeld door deze organisatie.');
        }
    }
}
