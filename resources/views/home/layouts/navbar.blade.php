<div class="nav-menu fixed-top">
    <div class="container">
        <div class="row">
            <div class="col-md-12">
                <nav class="navbar navbar-dark navbar-expand-lg">
                    <a class="navbar-brand" href="{{url('/')}}">
                        <!-- <img src="{{$website_logo_url}}" class="img-fluid" alt="logo"> -->
                        <span id="website_name_navbar">{{$website_name}}</span> 
                        <p class="tagline-title">A smart way to Travel</p>
                    </a>
                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbar" aria-controls="navbar" aria-expanded="false" aria-label="Toggle navigation"> 
                        <span class="navbar-toggler-icon"></span> 
                    </button>
                    <div class="collapse navbar-collapse" id="navbar">
                        <ul class="navbar-nav ml-auto">
                            <li class="nav-item"> <a class="nav-link" href="{{route('home')}}#home">HOME <span class="sr-only">(current)</span></a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{route('home')}}#features">FEATURES</a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{route('home')}}#gallery">GALLERY</a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{route('priceestimate.show')}}">PRICE ESTIMATE</a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{route('home')}}#contact">CONTACT</a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{url('offers')}}">OFFERS</a> </li>
                            <li class="nav-item"> <a class="nav-link" href="{{$driver_portal_url}}">DRIVER PORTAL</a> </li>
                            <li class="nav-item"><a href="{{route('home')}}#download" class="btn btn-outline-light my-3 my-sm-0 ml-lg-3">Download</a></li>
                        </ul>
                    </div>
                </nav>
            </div>
        </div>
    </div>
</div>