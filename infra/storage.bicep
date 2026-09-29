// The storage account uploaded videos are kept in, and the one container and CORS rule they need.
//
// Everything else this application stores is on fortrabbit's disk; a video is too large for a PHP
// request, so the browser writes it straight to this container and the player reads it straight
// back, each on a link the application signs with the account key. That is why:
//
//  - the container is private and public access is off for the whole account — a signed link is
//    the only way in, and the application only signs one after the same permission check the
//    rest of the API asks;
//  - CORS allows PUT from the origins the panel and the frontend are served from, and nothing
//    else. A player reading a video needs no CORS rule: a <video> element without `crossorigin`
//    is not a CORS request;
//  - shared-key access stays on. The application runs on fortrabbit, not in Azure, so it has no
//    managed identity, and it is the key that signs the links.
//
// Develop was set up by hand to match this (stkompazdevelop, which the .NET application's
// foundation.bicep created). Production has no account yet. To create one:
//
//   az deployment group create -g Kompaz -f infra/storage.bicep \
//     -p storageAccountName=stkompazprod corsOrigins='["https://<production domain>"]'
//
// then put its connection string in the production app's AZURE_STORAGE_CONNECTION_STRING.

@description('The storage account. Globally unique, lowercase letters and digits.')
param storageAccountName string

param location string = resourceGroup().location

@description('The origins the panel and the frontend are served from, which upload to the container.')
param corsOrigins array

@description('The container videos are kept in. Must match AZURE_STORAGE_VIDEO_CONTAINER.')
param videoContainerName string = 'videos'

resource storage 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: storageAccountName
  location: location
  sku: {
    // Locally redundant, the product's choice: a video can be uploaded again.
    name: 'Standard_LRS'
  }
  kind: 'StorageV2'
  properties: {
    accessTier: 'Hot'
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
    allowBlobPublicAccess: false
    allowSharedKeyAccess: true
  }
}

resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2023-05-01' = {
  parent: storage
  name: 'default'
  properties: {
    cors: {
      corsRules: [
        {
          allowedOrigins: corsOrigins
          allowedMethods: ['PUT', 'OPTIONS']
          allowedHeaders: ['x-ms-*', 'content-type']
          exposedHeaders: ['etag']
          maxAgeInSeconds: 3600
        }
      ]
    }
  }
}

resource videoContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-05-01' = {
  parent: blobService
  name: videoContainerName
  properties: {
    publicAccess: 'None'
  }
}

output blobEndpoint string = storage.properties.primaryEndpoints.blob
