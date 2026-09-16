<?php

namespace App\Filament\Resources\BlogPosts\Pages;

use App\Filament\Resources\BlogPosts\BlogPostResource;
use App\Models\BlogPost;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogPost extends CreateRecord
{
    protected static string $resource = BlogPostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->prepareBlogPostData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareBlogPostData(array $data): array
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : BlogPost::uniqueSlug((string) ($data['title'] ?? 'post'));

        if (($data['status'] ?? null) === BlogPost::STATUS_PUBLISHED && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        if (($data['status'] ?? null) === BlogPost::STATUS_DRAFT) {
            $data['published_at'] = null;
        }

        $data['sort_order'] ??= ((int) BlogPost::query()->max('sort_order')) + 1;

        return $data;
    }
}
