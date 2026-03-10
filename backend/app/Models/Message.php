<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function toArray()
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'userName' => $this->user->name,
            'content' => $this->content,
            'timestamp' => $this->created_at->toIso8601String(),
        ];
    }
}
