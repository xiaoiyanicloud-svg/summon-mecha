@extends('shop::dev.layouts.app')

@section('title', '审核状态 · 开发者中心')

@section('content')
    <h1>审核状态</h1>

    <div class="card">
        @forelse ($records as $record)
            <table>
                <tr>
                    <td style="width:120px"><span class="badge">L{{ $record->layer }}</span></td>
                    <td style="width:120px">
                        <span class="badge {{ $record->result == \Webkul\Shop\Models\AuditRecord::RESULT_PASS ? 'ok' : '' }}">
                            {{ $record->result == \Webkul\Shop\Models\AuditRecord::RESULT_PASS ? '通过' : '驳回' }}
                        </span>
                    </td>
                    <td>
                        {{-- 驳回理由必须让开发者看得见：否则他只能靠群里问人 --}}
                        @if ($record->reason_detail)
                            <div>{{ $record->reason_category }}：{{ $record->reason_detail }}</div>
                        @else
                            <span class="muted">无</span>
                        @endif
                        @if ($record->suggestion)
                            <div class="muted">建议：{{ $record->suggestion }}</div>
                        @endif
                    </td>
                    <td style="width:170px" class="muted">{{ optional($record->created_at)->toDateTimeString() }}</td>
                </tr>
            </table>
        @empty
            <p class="muted">还没有审核记录。提交审核后这里会显示结果与驳回理由。</p>
        @endforelse
    </div>
@endsection
