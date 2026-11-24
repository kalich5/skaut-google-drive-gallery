export function isError(
	data:
		| GalleryResponse
		| ListGalleryFoldersResponse
		| ListGdriveDirResponse
		| PageResponse
): data is ErrorResponse {
	return 'error' in data;
}
