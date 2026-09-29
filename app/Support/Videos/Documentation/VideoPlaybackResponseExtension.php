<?php

declare(strict_types=1);

namespace App\Support\Videos\Documentation;

use App\Providers\AppServiceProvider;
use App\Support\Videos\VideoPlayback;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Describes an uploaded video's address as the redirect it is.
 *
 * Scramble sees a {@see VideoPlayback} as an object it knows nothing about and documents it as
 * JSON, which is what a generated client would then try to parse. It is a 302 with a `Location`
 * and no body; the frontend generates its client from `/docs/api`, so the document has to say so.
 * Registered in {@see AppServiceProvider}.
 */
final class VideoPlaybackResponseExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(VideoPlayback::class);
    }

    public function toResponse(Type $type): ?Response
    {
        return Response::make(HttpResponse::HTTP_FOUND)
            ->setDescription('A redirect to a short-lived, read-only link to the video, which answers range requests. Follow it; never cache it.')
            ->addHeader('Location', new Header(
                description: 'The video, on a signed link that expires.',
                required: true,
                schema: Schema::fromType((new OpenApi\StringType)->format('uri')),
            ))
            ->addHeader('Cache-Control', new Header(
                description: 'The link inside the redirect expires, so the redirect is not to be kept.',
                required: true,
                schema: Schema::fromType((new OpenApi\StringType)->const('no-store, private')),
            ));
    }
}
