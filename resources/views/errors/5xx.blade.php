@include('errors.page', ['page' => \App\Http\ErrorPage::for($exception->getStatusCode(), request())])
