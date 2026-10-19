@props(['document'])
<div class="document-preview">
@if($document->mime === 'application/pdf')
<object data="{{ route('documents.show', ['document'=>$document, 'preview'=>1]) }}" type="application/pdf" aria-label="Preview of {{ $document->original_name }}"><p>PDF preview is unavailable in this browser. Use the link below to open it.</p></object>
@else
<img src="{{ route('documents.show',$document) }}" alt="Preview of {{ $document->original_name }}" loading="lazy">
@endif
<a href="{{ route('documents.show', ['document'=>$document, 'preview'=>1]) }}" target="_blank" rel="noopener noreferrer">Open {{ $document->original_name }}</a>
</div>
