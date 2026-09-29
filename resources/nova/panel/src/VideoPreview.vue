<template>
  <div v-if="preview" class="space-y-1">
    <div v-if="preview.kind === 'youtube' || preview.kind === 'vimeo'" class="w-full max-w-md" style="aspect-ratio: 16 / 9">
      <iframe
        :src="preview.src"
        class="w-full h-full rounded"
        title="Video"
        allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
        allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
      />
    </div>

    <video
      v-else-if="preview.kind === 'upload' || preview.kind === 'file'"
      :src="preview.src"
      controls
      preload="metadata"
      class="w-full max-w-md rounded bg-black"
    />

    <a
      v-else
      :href="preview.src"
      target="_blank"
      rel="noopener noreferrer"
      class="link-default break-all"
    >
      {{ labels.openLabel }}: {{ preview.src }}
    </a>
  </div>

  <p v-else class="text-sm text-gray-500">{{ labels.emptyLabel }}</p>
</template>

<script>
/**
 * The saved row's video: an embedded YouTube or Vimeo player, a <video> for an upload or a link
 * straight to a file, and a plain link for anything else. Every address here came from the server,
 * which builds an embed from the video's id rather than passing on what was typed.
 */
export default {
  props: ['preview', 'labels'],
}
</script>
