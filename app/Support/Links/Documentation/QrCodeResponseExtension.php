<?php

declare(strict_types=1);

namespace App\Support\Links\Documentation;

use App\Providers\AppServiceProvider;
use App\Support\Links\QrCodeDownload;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Documents a shared course's QR code as the PNG it is, rather than as JSON.
 *
 * Scramble sees a `Responsable` and can say nothing of what it sends; the frontend generates its
 * client from `/docs/api`, and a client that expected JSON would try to parse an image.
 * Registered in {@see AppServiceProvider}.
 */
final class QrCodeResponseExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(QrCodeDownload::class);
    }

    public function toResponse(Type $type): Response
    {
        return Response::make(HttpResponse::HTTP_OK)
            ->setDescription('The QR code, which encodes the course\'s QR address for the caller\'s organization.')
            ->setContent('image/png', Schema::fromType((new OpenApi\StringType)->format('binary')))
            ->addHeader('Content-Disposition', new Header(
                description: '`attachment` when `?download` was asked for, `inline` otherwise, with a file name made of the two slugs.',
                required: true,
                schema: Schema::fromType(new OpenApi\StringType),
            ));
    }
}
