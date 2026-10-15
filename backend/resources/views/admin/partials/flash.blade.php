@if (session('status'))<div class="flash" role="status">@include('admin.partials.icon', ['name' => 'check'])<span>{{ session('status') }}</span></div>@endif
@if (session('error'))<div class="flash bad" role="alert">@include('admin.partials.icon', ['name' => 'warning'])<span>{{ session('error') }}</span></div>@endif
@if ($errors->any())
    <ul class="errors" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
@endif
