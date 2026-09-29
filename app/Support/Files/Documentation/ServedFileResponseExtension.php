<?php

declare(strict_types=1);

namespace App\Support\Files\Documentation;

use App\Providers\AppServiceProvider;
use App\Support\Files\ServedFile;
use App\Support\Images\LogoImage;
use App\Support\Images\ServedLogo;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Documents a stored file on its way out as the bytes it is, rather than as JSON.
 *
 * Scramble reads a response's media type off a literal `Content-Type` header, and this one is
 * never literal: it is whatever was read out of the bytes on upload. Left alone, every image and
 * video endpoint was documented as `application/json` carrying a string — which a generated client
 * dutifully tries to parse.
 *
 * A logo is one of the formats {@see LogoImage} accepts, the placeholder included, so it is
 * documented under exactly those. {@see ServedFile} also answers for uploaded videos, whose formats
 * are not this application's to list, so it is documented as a binary of whichever type it was
 * stored under.
 *
 * Registered in {@see AppServiceProvider}, after Scramble's own `Responsable` handling, which it
 * takes priority over.
 */
final class ServedFileResponseExtension extends TypeToSchemaExtension
{
    /** @var list<string> */
    private const array LOGO_MEDIA_TYPES = [LogoImage::PNG, LogoImage::JPEG, LogoImage::SVG, LogoImage::WEBP];

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ($type->isInstanceOf(ServedFile::class) || $type->isInstanceOf(ServedLogo::class));
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $isLogo = $type->isInstanceOf(ServedLogo::class);

        $response = Response::make(HttpResponse::HTTP_OK)
            ->setDescription($isLogo
                ? 'The logo, or the placeholder when the organization has none. Served under the media type read from its bytes.'
                : 'The stored file, served under the media type read from its bytes when it was uploaded.');

        foreach ($isLogo ? self::LOGO_MEDIA_TYPES : ['application/octet-stream'] as $mediaType) {
            $response->setContent($mediaType, Schema::fromType((new OpenApi\StringType)->format('binary')));
        }

        return $response
            ->addHeader('Content-Security-Policy', new Header(
                description: 'Forbids the file from loading anything or running script, which is what makes serving an SVG from this origin safe.',
                required: true,
                schema: Schema::fromType((new OpenApi\StringType)->const("default-src 'none'; sandbox")),
            ))
            ->addHeader('X-Content-Type-Options', new Header(
                description: 'Stops a browser from deciding for itself that the bytes are something other than their media type.',
                required: true,
                schema: Schema::fromType((new OpenApi\StringType)->const('nosniff')),
            ));
    }
}
