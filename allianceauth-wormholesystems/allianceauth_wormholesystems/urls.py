from django.urls import path

from . import views

app_name = "wormholesystems"

urlpatterns = [
    path("launch/", views.launch, name="launch"),
    path("api/user/", views.user_info_api, name="user_api"),
]
