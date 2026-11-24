@extends('errors.layout')
@section('code', '403')
@section('title', 'Access denied')
@section('message')
    {{ $exception?->getMessage() ?: 'You do not have permission to view this page.' }}
    If you believe this is a mistake, please contact an administrator.
@endsection
