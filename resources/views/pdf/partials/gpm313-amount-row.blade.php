<table class="amounts">
    <tr>
        @foreach ($fields as $field)
            <td>
                @if (filled($field['n'] ?? null))
                    <div class="label"><strong>{{ $field['n'] }}</strong> {{ $field['label'] }}</div>
                    <table class="cells">
                        <tr>
                            @foreach ($field['cells'] as $cell)
                                <td @class(['comma' => $cell === ','])>{{ $cell !== ' ' ? $cell : '' }}</td>
                            @endforeach
                        </tr>
                    </table>
                @endif
            </td>
        @endforeach
    </tr>
</table>
