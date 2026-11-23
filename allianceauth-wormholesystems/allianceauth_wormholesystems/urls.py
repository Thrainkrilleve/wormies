from django.urls import path

from . import views

app_name = "wormholesystems"

urlpatterns = [
    path("launch/", views.launch, name="launch"),
    path("api/user/", views.user_info_api, name="user_api"),
    # Keep this last. Alliance Auth's decorate_url_patterns returns at the first
    # excluded view, so a pattern after this one would also skip the login gate.
    path("api/character-token/", views.character_token, name="character_token"),
]
